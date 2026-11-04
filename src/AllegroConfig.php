<?php

declare(strict_types=1);

namespace Beziworld\Allegro;

use Beziworld\Allegro\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable configuration of one Allegro account.
 *
 * Built from the shared options of config/allegro.php merged with the
 * options of a named account (or the overrides passed to
 * Allegro::forUser()).
 */
final readonly class AllegroConfig
{
    public const string API_PRODUCTION = 'https://api.allegro.pl';

    public const string API_SANDBOX = 'https://api.allegro.pl.allegrosandbox.pl';

    public const string OAUTH_PRODUCTION = 'https://allegro.pl/auth/oauth';

    public const string OAUTH_SANDBOX = 'https://allegro.pl.allegrosandbox.pl/auth/oauth';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public string $userAgent,
        public bool $sandbox = false,
        public ?string $redirectUri = null,
        public array $scopes = [],
        public ?string $language = 'pl-PL',
        public ?string $cacheStore = null,
        public string $cachePrefix = 'allegro',
        public int $timeout = 30,
        public int $pollAttempts = 30,
        public int $pollIntervalMs = 1000,
        public ?string $logChannel = null,
        #[\SensitiveParameter]
        public ?string $refreshToken = null,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $string = static fn(array $source, string $key): ?string => is_scalar($source[$key] ?? null) && trim((string) $source[$key]) !== '' ? trim((string) $source[$key]) : null;

        /** @var array<string, mixed> $cache */
        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        /** @var array<string, mixed> $polling */
        $polling = is_array($config['polling'] ?? null) ? $config['polling'] : [];

        $scopes = $config['scopes'] ?? [];
        $scopes = is_string($scopes) ? preg_split('/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY) : $scopes;
        $scopes = is_array($scopes) ? array_values(array_map(strval(...), array_filter($scopes, is_string(...)))) : [];

        return new self(
            clientId: $string($config, 'client_id') ?? throw InvalidConfiguration::missing('client_id', 'ALLEGRO_CLIENT_ID'),
            clientSecret: $string($config, 'client_secret') ?? throw InvalidConfiguration::missing('client_secret', 'ALLEGRO_CLIENT_SECRET'),
            userAgent: $string($config, 'user_agent') ?? throw InvalidConfiguration::missing('user_agent (for example "MyShop/1.0 (+https://myshop.pl)")', 'ALLEGRO_USER_AGENT'),
            sandbox: filter_var($config['sandbox'] ?? false, FILTER_VALIDATE_BOOL),
            redirectUri: $string($config, 'redirect_uri'),
            scopes: $scopes,
            language: $string($config, 'language'),
            cacheStore: $string($cache, 'store'),
            cachePrefix: $string($cache, 'prefix') ?? 'allegro',
            timeout: is_numeric($config['timeout'] ?? null) && (int) $config['timeout'] > 0 ? (int) $config['timeout'] : 30,
            pollAttempts: is_numeric($polling['attempts'] ?? null) ? max(1, (int) $polling['attempts']) : 30,
            pollIntervalMs: is_numeric($polling['interval_ms'] ?? null) ? max(0, (int) $polling['interval_ms']) : 1000,
            logChannel: $string($config, 'log_channel'),
            refreshToken: $string($config, 'refresh_token'),
        );
    }

    public function apiUrl(): string
    {
        return $this->sandbox ? self::API_SANDBOX : self::API_PRODUCTION;
    }

    public function oauthUrl(): string
    {
        return $this->sandbox ? self::OAUTH_SANDBOX : self::OAUTH_PRODUCTION;
    }

    /**
     * "sandbox" or "production"; tokens are not interchangeable between the environments.
     */
    public function environment(): string
    {
        return $this->sandbox ? 'sandbox' : 'production';
    }
}
