<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Exceptions;

use Throwable;

/**
 * Raised when Allegro does not issue, refresh or accept an OAuth2 token.
 *
 * error() holds the OAuth2 error code (for example "invalid_grant",
 * "access_denied" or "unauthorized") when the server returned one, and
 * "not_connected" when no token was ever stored for the account.
 */
final class AuthenticationException extends AllegroException
{
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $error = null,
        private readonly ?string $traceId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public static function rejected(int $status, ?string $error, ?string $description, ?string $traceId = null): self
    {
        return new self(
            sprintf('Allegro OAuth2 request failed (HTTP %d): %s', $status, $description ?? $error ?? 'no reason given'),
            $status,
            $error,
            $traceId,
        );
    }

    public static function notConnected(string $key): self
    {
        return new self(sprintf(
            'No Allegro token is stored for "%s". Connect the account first: redirect the seller to Allegro::authorizationUrl() and call exchangeCode(), or use the device flow (php artisan allegro:device-login).',
            $key,
        ), null, 'not_connected');
    }

    public static function lockTimeout(string $key, Throwable $previous): self
    {
        return new self(sprintf('Another process is refreshing the Allegro token of "%s" and did not finish in time; try again.', $key), previous: $previous);
    }

    public static function deviceFlowEnded(string $error): self
    {
        return new self(sprintf('Allegro device authorization ended with "%s"; start a new device flow.', $error), 400, $error);
    }

    public static function deviceFlowExpired(): self
    {
        return new self('The Allegro device code expired before the user confirmed it; start a new device flow.', null, 'expired_token');
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    /**
     * Whether the authorization was revoked or expired and the seller must connect the account again.
     */
    public function requiresReauthorization(): bool
    {
        return in_array($this->error, ['invalid_grant', 'invalid_token', 'unauthorized', 'not_connected', 'access_denied', 'expired_token'], true);
    }
}
