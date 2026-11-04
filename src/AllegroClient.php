<?php

declare(strict_types=1);

namespace Beziworld\Allegro;

use Beziworld\Allegro\Contracts\TokenStore;
use Beziworld\Allegro\Data\AccessToken;
use Beziworld\Allegro\Data\DeviceCode;
use Beziworld\Allegro\Data\DevicePollResult;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Exceptions\AuthenticationException;
use Beziworld\Allegro\OAuth\OAuthClient;
use Beziworld\Allegro\Resources\Billing;
use Beziworld\Allegro\Resources\Catalog;
use Beziworld\Allegro\Resources\CustomerReturns;
use Beziworld\Allegro\Resources\Messaging;
use Beziworld\Allegro\Resources\Offers;
use Beziworld\Allegro\Resources\Orders;
use Beziworld\Allegro\Resources\ShipmentManagement;
use Beziworld\Allegro\Support\Query;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;

/**
 * Allegro REST API client bound to one seller account or to the application itself.
 *
 * Every request carries the bearer token of the account, the mandatory
 * User-Agent, the versioned media type (application/vnd.allegro.public.v1+json,
 * or the beta one for beta resources) and Accept-Language. Expired access
 * tokens are refreshed under a cache lock, because Allegro refresh tokens
 * are single-use and rotate. After HTTP 401 the token is refreshed and the
 * request is repeated once: a rejected request was not processed, so this
 * is the only automatic retry, also for writes.
 *
 * Endpoints are grouped in resources (orders(), offers(), ...); any other
 * endpoint of the API is reachable with request().
 */
final class AllegroClient
{
    public const string MEDIA_TYPE = 'application/vnd.allegro.public.v1+json';

    public const string BETA_MEDIA_TYPE = 'application/vnd.allegro.beta.v1+json';

    private ?AccessToken $token = null;

    private ?string $usedAccessToken = null;

    private ?string $lastTraceId = null;

    public function __construct(
        private readonly AllegroConfig $config,
        private readonly HttpFactory $http,
        private readonly OAuthClient $oauth,
        private readonly TokenStore $tokens,
        private readonly Repository $cache,
        private readonly string $key,
        private readonly bool $application = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function config(): AllegroConfig
    {
        return $this->config;
    }

    /**
     * Token store key of the account: the account name or the key passed to Allegro::forUser().
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Whether the client uses an application (client credentials) token instead of a seller token.
     */
    public function isApplication(): bool
    {
        return $this->application;
    }

    public function orders(): Orders
    {
        return new Orders($this);
    }

    public function offers(): Offers
    {
        return new Offers($this);
    }

    /**
     * Wysyłam z Allegro: shipments, labels, protocols and courier pickups.
     */
    public function shipmentManagement(): ShipmentManagement
    {
        return new ShipmentManagement($this);
    }

    public function billing(): Billing
    {
        return new Billing($this);
    }

    public function messaging(): Messaging
    {
        return new Messaging($this);
    }

    /**
     * Customer returns (beta resource).
     */
    public function customerReturns(): CustomerReturns
    {
        return new CustomerReturns($this);
    }

    /**
     * Categories, parameters and the product catalogue.
     */
    public function catalog(): Catalog
    {
        return new Catalog($this);
    }

    /**
     * Basic data of the authorized seller (GET /me): id, login, firstName, lastName, company, email, baseMarketplace.
     *
     * @return array<array-key, mixed>
     */
    public function me(): array
    {
        return $this->request('GET', '/me');
    }

    /**
     * Sends any API request and returns the decoded JSON body (an empty array for empty responses).
     *
     * @param array<string, mixed> $query Repeated values as lists, dotted names as keys, e.g. ['publication.status' => ['ACTIVE', 'ENDED']].
     * @param array<array-key, mixed>|null $body JSON body; null sends none.
     * @param string $mediaType MEDIA_TYPE, or BETA_MEDIA_TYPE for beta resources.
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, string $mediaType = self::MEDIA_TYPE): array
    {
        $data = $this->send($method, $path, $query, $body, $mediaType)->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Sends any API request and returns the HTTP response, for binary content or headers (Location, Retry-After).
     *
     * Responses with status 400 and above are thrown as ApiException.
     *
     * @param array<string, mixed> $query
     * @param array<array-key, mixed>|null $body
     * @param string|null $accept Accept header; defaults to the media type.
     */
    public function send(string $method, string $path, array $query = [], ?array $body = null, string $mediaType = self::MEDIA_TYPE, ?string $accept = null, bool $followRedirects = true): Response
    {
        $url = Query::append('/' . ltrim($path, '/'), $query);
        $method = strtoupper($method);

        return $this->dispatch($method, $url, function (PendingRequest $request) use ($method, $url, $body, $mediaType, $accept, $followRedirects): Response {
            $request = $request->accept($accept ?? $mediaType);

            if (! $followRedirects) {
                $request = $request->withoutRedirecting();
            }

            if ($body !== null) {
                $request = $request->withBody(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $mediaType);
            }

            return $request->send($method, $url);
        });
    }

    /**
     * Uploads a multipart/form-data request with one file and optional text fields.
     *
     * @param array<string, string> $fields
     */
    public function sendMultipart(string $path, string $fileField, string $contents, string $fileName, string $fileContentType, array $fields = [], string $accept = self::MEDIA_TYPE): Response
    {
        $url = '/' . ltrim($path, '/');

        return $this->dispatch('POST', $url, static fn(PendingRequest $request): Response => $request
            ->accept($accept)
            ->attach($fileField, $contents, $fileName, ['Content-Type' => $fileContentType])
            ->post($url, $fields));
    }

    /**
     * Sends raw binary content, for example a message attachment.
     */
    public function sendBinary(string $method, string $path, string $contents, string $contentType, string $accept = self::MEDIA_TYPE): Response
    {
        $url = '/' . ltrim($path, '/');
        $method = strtoupper($method);

        return $this->dispatch($method, $url, static fn(PendingRequest $request): Response => $request
            ->accept($accept)
            ->withBody($contents, $contentType)
            ->send($method, $url));
    }

    /**
     * Trace-Id header of the last response, for reports to Allegro support.
     */
    public function lastTraceId(): ?string
    {
        return $this->lastTraceId;
    }

    /**
     * Returns a valid access token, refreshing it (or obtaining an application token) when needed.
     *
     * @throws AuthenticationException When the account was never connected or the refresh token was rejected.
     */
    public function accessToken(): string
    {
        if ($this->token !== null && $this->token->isValid()) {
            return $this->token->accessToken;
        }

        $stored = $this->tokens->get($this->key);

        if ($stored !== null && $stored->isValid()) {
            return ($this->token = $stored)->accessToken;
        }

        return $this->withLock('token', function (): string {
            // Another process may have refreshed the token while this one waited for the lock.
            $stored = $this->tokens->get($this->key);

            if ($stored !== null && $stored->isValid()) {
                return ($this->token = $stored)->accessToken;
            }

            if ($this->application) {
                return $this->store($this->oauth->clientCredentials())->accessToken;
            }

            $refreshToken = $stored->refreshToken ?? $this->config->refreshToken;

            if ($refreshToken === null) {
                throw AuthenticationException::notConnected($this->key);
            }

            return $this->store($this->oauth->refresh($refreshToken))->accessToken;
        });
    }

    /**
     * Stored token of the account, without refreshing it.
     */
    public function token(): ?AccessToken
    {
        return $this->tokens->get($this->key);
    }

    /**
     * Whether a token (or a configured refresh token) is available for the account.
     */
    public function isConnected(): bool
    {
        return $this->application || $this->tokens->get($this->key) !== null || $this->config->refreshToken !== null;
    }

    /**
     * Exchanges the authorization code from the redirect URI and stores the tokens for this account.
     *
     * @param string|null $codeVerifier The PKCE verifier used for Allegro::authorizationUrl(), if any.
     */
    public function exchangeCode(string $code, ?string $codeVerifier = null): AccessToken
    {
        return $this->store($this->oauth->exchangeCode($code, $codeVerifier));
    }

    /**
     * Polls the device flow once; a granted token is stored for this account.
     */
    public function pollDeviceToken(DeviceCode|string $deviceCode): DevicePollResult
    {
        $result = $this->oauth->pollDeviceToken($deviceCode instanceof DeviceCode ? $deviceCode->deviceCode : $deviceCode);

        if ($result->token !== null) {
            $this->store($result->token);
        }

        return $result;
    }

    /**
     * Polls the device flow until the seller confirms the code, honouring "interval" and "slow_down".
     *
     * @param (Closure(DevicePollResult): void)|null $onPoll Called after every pending poll, e.g. to print progress.
     *
     * @throws AuthenticationException When the seller denied access or the code expired.
     */
    public function waitForDeviceToken(DeviceCode $deviceCode, ?Closure $onPoll = null): AccessToken
    {
        $interval = $deviceCode->interval;

        while (! $deviceCode->isExpired()) {
            Sleep::sleep($interval);
            $result = $this->pollDeviceToken($deviceCode);

            if ($result->token !== null) {
                return $result->token;
            }

            if ($result->status === DevicePollResult::SLOW_DOWN) {
                $interval += 5;
            }

            if ($onPoll !== null) {
                $onPoll($result);
            }
        }

        throw AuthenticationException::deviceFlowExpired();
    }

    /**
     * Stores a token obtained elsewhere, for example migrated from another integration.
     *
     * @param AccessToken|array<array-key, mixed> $token
     */
    public function storeToken(AccessToken|array $token): AccessToken
    {
        return $this->store($token instanceof AccessToken ? $token : AccessToken::fromArray($token));
    }

    /**
     * Forgets the stored tokens of this account.
     */
    public function disconnect(): void
    {
        $this->token = null;
        $this->tokens->forget($this->key);
    }

    /**
     * Runs the callback while holding a cache lock scoped to this account.
     *
     * @template TResult
     *
     * @param Closure(): TResult $callback
     * @return TResult
     */
    public function withLock(string $name, Closure $callback, int $seconds = 30, int $wait = 20): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store->lock($this->scopedKey('lock:' . $name), $seconds)->block($wait, $callback);
        } catch (LockTimeoutException $exception) {
            throw AuthenticationException::lockTimeout($this->key, $exception);
        }
    }

    /**
     * Cache key scoped to the environment, the application and this account.
     */
    public function scopedKey(string $name): string
    {
        return sprintf('%s:%s:%s:%s:%s', $this->config->cachePrefix, $this->config->environment(), $this->config->clientId, $this->key, $name);
    }

    /**
     * Cache repository used for locks and polling cursors.
     */
    public function cache(): Repository
    {
        return $this->cache;
    }

    /**
     * Waits between polls of an asynchronous operation.
     */
    public function pause(?int $retryAfterSeconds = null): void
    {
        Sleep::usleep(($retryAfterSeconds !== null && $retryAfterSeconds > 0 ? $retryAfterSeconds * 1000 : $this->config->pollIntervalMs) * 1000);
    }

    private function store(AccessToken $token): AccessToken
    {
        if ($token->refreshToken === null && ! $this->application) {
            // Some responses may omit the refresh token; keep the current one so the authorization is not lost.
            $current = $this->tokens->get($this->key);
            $token = new AccessToken($token->accessToken, $token->expiresAt, $current?->refreshToken, $token->scope);
        }

        $this->tokens->put($this->key, $token);

        return $this->token = $token;
    }

    /**
     * Marks the access token that was just rejected as expired, so the next call refreshes it.
     */
    private function invalidateAccessToken(): void
    {
        $this->token = null;
        $stored = $this->tokens->get($this->key);

        if ($stored === null || $stored->accessToken !== $this->usedAccessToken) {
            // Another process already replaced the rejected token.
            return;
        }

        if ($this->application || $stored->refreshToken === null) {
            $this->tokens->forget($this->key);

            return;
        }

        $this->tokens->put($this->key, $stored->expire());
    }

    private function pending(): PendingRequest
    {
        $this->usedAccessToken = $this->accessToken();

        $request = $this->http->createPendingRequest()
            ->baseUrl($this->config->apiUrl())
            ->timeout($this->config->timeout)
            ->withUserAgent($this->config->userAgent)
            ->withToken($this->usedAccessToken);

        return $this->config->language !== null ? $request->withHeaders(['Accept-Language' => $this->config->language]) : $request;
    }

    /**
     * Executes a request built by the callback and converts failures into exceptions.
     *
     * @param Closure(PendingRequest): Response $build
     */
    private function dispatch(string $method, string $url, Closure $build, bool $retryUnauthorized = true): Response
    {
        try {
            $response = $build($this->pending());
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }

        $traceId = $response->header('Trace-Id');
        $this->lastTraceId = $traceId !== '' ? $traceId : null;

        if ($response->status() < 400) {
            return $response;
        }

        if ($response->status() === 401 && $retryUnauthorized) {
            $this->invalidateAccessToken();

            return $this->dispatch($method, $url, $build, false);
        }

        $payload = $response->json();
        $retryAfter = $response->header('Retry-After');

        $this->logger?->warning('Allegro request failed', [
            'method' => $method,
            'url' => $url,
            'status' => $response->status(),
            'trace_id' => $this->lastTraceId,
            'account' => $this->key,
        ]);

        throw ApiException::fromResponse(is_array($payload) ? $payload : [], $response->status(), $this->lastTraceId, is_numeric($retryAfter) ? (int) $retryAfter : null);
    }
}
