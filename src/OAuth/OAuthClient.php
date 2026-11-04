<?php

declare(strict_types=1);

namespace Beziworld\Allegro\OAuth;

use Beziworld\Allegro\AllegroConfig;
use Beziworld\Allegro\Data\AccessToken;
use Beziworld\Allegro\Data\DeviceCode;
use Beziworld\Allegro\Data\DevicePollResult;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Exceptions\AuthenticationException;
use Beziworld\Allegro\Exceptions\InvalidConfiguration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Talks to the Allegro OAuth2 server (allegro.pl/auth/oauth).
 *
 * Supports the authorization code flow (optionally with PKCE), the device
 * flow, the client credentials grant and refreshing. The client is
 * authenticated with HTTP Basic (client_id:client_secret), except for
 * the code exchange with PKCE, where Allegro expects client_id and
 * code_verifier in the form instead. This class does not store tokens;
 * AllegroClient does.
 */
final readonly class OAuthClient
{
    public const string DEVICE_CODE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    public function __construct(
        private AllegroConfig $config,
        private HttpFactory $http,
    ) {}

    /**
     * URL of the Allegro consent page for the authorization code flow.
     *
     * @param string|null $state Opaque value returned to the redirect URI; use it against CSRF.
     * @param string|null $codeVerifier PKCE verifier (see Pkce::generateVerifier()); its S256 challenge is sent.
     * @param list<string>|null $scopes Scopes to request; null uses the configured ones, empty requests all scopes of the application.
     * @param string|null $prompt "confirm" shows the account confirmation screen.
     */
    public function authorizationUrl(?string $state = null, ?string $codeVerifier = null, ?array $scopes = null, ?string $prompt = null): string
    {
        $scopes ??= $this->config->scopes;

        return $this->config->oauthUrl() . '/authorize?' . http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->redirectUri(),
            'code_challenge_method' => $codeVerifier !== null ? 'S256' : null,
            'code_challenge' => $codeVerifier !== null ? Pkce::challenge($codeVerifier) : null,
            'scope' => $scopes !== [] ? implode(' ', $scopes) : null,
            'state' => $state,
            'prompt' => $prompt,
        ], static fn(?string $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the authorization code (valid for a few seconds) for a token pair.
     */
    public function exchangeCode(string $code, ?string $codeVerifier = null): AccessToken
    {
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri()];

        if ($codeVerifier !== null) {
            return $this->token($form + ['code_verifier' => $codeVerifier, 'client_id' => $this->config->clientId], basicAuth: false);
        }

        return $this->token($form);
    }

    /**
     * Uses a refresh token; Allegro answers with a new access token and a new refresh token.
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken): AccessToken
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Application token for resources marked "bearer-token-for-application" (categories, parameters, carriers).
     */
    public function clientCredentials(): AccessToken
    {
        return $this->token(['grant_type' => 'client_credentials']);
    }

    /**
     * Starts the device flow: returns the user code to show to the seller and the device code to poll with.
     *
     * The application must be registered in Allegro as a device application.
     *
     * @param list<string>|null $scopes Scopes to request; null uses the configured ones.
     */
    public function startDeviceFlow(?array $scopes = null): DeviceCode
    {
        $scopes ??= $this->config->scopes;
        $form = array_filter([
            'client_id' => $this->config->clientId,
            'scope' => $scopes !== [] ? implode(' ', $scopes) : null,
        ], static fn(?string $value): bool => $value !== null);

        $response = $this->post('/device?' . http_build_query(['client_id' => $this->config->clientId]), $form, true);
        $data = self::decode($response);

        if (! $response->successful() || ! is_string($data['device_code'] ?? null)) {
            throw self::rejected($response, $data);
        }

        return DeviceCode::fromArray($data);
    }

    /**
     * Polls the token endpoint once with a device code.
     *
     * @throws AuthenticationException When the seller denied access or the device code is invalid, used or expired.
     */
    public function pollDeviceToken(string $deviceCode): DevicePollResult
    {
        $response = $this->post('/token', ['grant_type' => self::DEVICE_CODE_GRANT, 'device_code' => $deviceCode], true);
        $data = self::decode($response);

        if ($response->successful() && is_string($data['access_token'] ?? null)) {
            return new DevicePollResult(DevicePollResult::GRANTED, AccessToken::fromArray($data));
        }

        $error = is_string($data['error'] ?? null) ? $data['error'] : null;

        return match ($error) {
            'authorization_pending' => new DevicePollResult(DevicePollResult::PENDING),
            'slow_down' => new DevicePollResult(DevicePollResult::SLOW_DOWN),
            'access_denied', 'expired_token' => throw AuthenticationException::deviceFlowEnded($error),
            default => throw self::rejected($response, $data),
        };
    }

    /**
     * @param array<string, string> $form
     */
    private function token(array $form, bool $basicAuth = true): AccessToken
    {
        $response = $this->post('/token', $form, $basicAuth);
        $data = self::decode($response);

        if (! $response->successful() || ! is_string($data['access_token'] ?? null)) {
            throw self::rejected($response, $data);
        }

        return AccessToken::fromArray($data);
    }

    /**
     * @param array<string, string> $form
     */
    private function post(string $path, array $form, bool $basicAuth): Response
    {
        $request = $this->http->createPendingRequest()
            ->baseUrl($this->config->oauthUrl())
            ->timeout($this->config->timeout)
            ->withUserAgent($this->config->userAgent)
            ->acceptJson()
            ->asForm();

        if ($basicAuth) {
            $request = $request->withBasicAuth($this->config->clientId, $this->config->clientSecret);
        }

        try {
            return $request->post($path, $form);
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function rejected(Response $response, array $data): AuthenticationException
    {
        $error = is_string($data['error'] ?? null) ? $data['error'] : null;
        $description = is_string($data['error_description'] ?? null) ? $data['error_description'] : null;
        $traceId = $response->header('Trace-Id');

        return AuthenticationException::rejected($response->status(), $error, $description, $traceId !== '' ? $traceId : null);
    }

    private function redirectUri(): string
    {
        return $this->config->redirectUri ?? throw InvalidConfiguration::missing('redirect_uri', 'ALLEGRO_REDIRECT_URI');
    }
}
