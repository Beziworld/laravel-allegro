<?php

declare(strict_types=1);

namespace Beziworld\Allegro\TokenStores;

use Beziworld\Allegro\Contracts\TokenStore;
use Beziworld\Allegro\Data\AccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Token store backed by a Laravel cache store.
 *
 * The cache store must be shared by every process that calls the API
 * (redis, database, file on a single server); the array store loses
 * tokens between requests. Entries with a refresh token are kept for 90
 * days, the lifetime of an Allegro refresh token.
 */
final readonly class CacheTokenStore implements TokenStore
{
    private const int REFRESH_TOKEN_TTL = 60 * 60 * 24 * 90;

    public function __construct(
        private Repository $cache,
        private string $prefix = 'allegro',
    ) {}

    public function get(string $key): ?AccessToken
    {
        $cached = $this->cache->get($this->cacheKey($key));

        return is_array($cached) ? AccessToken::fromArray($cached) : null;
    }

    public function put(string $key, AccessToken $token): void
    {
        $ttl = $token->refreshToken !== null
            ? self::REFRESH_TOKEN_TTL
            : max(60, (int) CarbonImmutable::now()->diffInSeconds($token->expiresAt, false));

        $this->cache->put($this->cacheKey($key), $token->toArray(), $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix . ':token:' . $key;
    }
}
