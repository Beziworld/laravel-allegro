<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Carbon\CarbonImmutable;

/**
 * OAuth2 access token with its single-use, rotating refresh token.
 *
 * Allegro access tokens live for 12 hours; refresh tokens live for three
 * months and are replaced on every refresh (the previous one stays usable
 * for 60 seconds). Client-credentials tokens have no refresh token.
 */
final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public CarbonImmutable $expiresAt,
        #[\SensitiveParameter]
        public ?string $refreshToken = null,
        public ?string $scope = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data Token endpoint response or the output of toArray().
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = match (true) {
            is_string($data['expires_at'] ?? null) => CarbonImmutable::parse($data['expires_at']),
            is_numeric($data['expires_in'] ?? null) => CarbonImmutable::now()->addSeconds((int) $data['expires_in']),
            default => CarbonImmutable::now()->addHours(12),
        };

        return new self(
            accessToken: is_string($data['access_token'] ?? null) ? $data['access_token'] : '',
            expiresAt: $expiresAt,
            refreshToken: is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : null,
            scope: is_string($data['scope'] ?? null) && $data['scope'] !== '' ? $data['scope'] : null,
        );
    }

    /**
     * @return array{access_token: string, expires_at: string, refresh_token: ?string, scope: ?string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
        ];
    }

    /**
     * Whether the access token can still be used for at least the given number of seconds.
     */
    public function isValid(int $marginSeconds = 300): bool
    {
        return $this->accessToken !== '' && $this->expiresAt->isAfter(CarbonImmutable::now()->addSeconds($marginSeconds));
    }

    /**
     * Copy that is already expired but keeps the refresh token, which forces a refresh on the next call.
     */
    public function expire(): self
    {
        return new self('', CarbonImmutable::now()->subSecond(), $this->refreshToken, $this->scope);
    }
}
