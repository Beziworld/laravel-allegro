<?php

declare(strict_types=1);

namespace Beziworld\Allegro;

use Beziworld\Allegro\Contracts\TokenStore;
use Beziworld\Allegro\Data\DeviceCode;
use Beziworld\Allegro\Exceptions\InvalidConfiguration;
use Beziworld\Allegro\OAuth\OAuthClient;
use Beziworld\Allegro\TokenStores\CacheTokenStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves Allegro clients for any number of seller accounts sharing one application.
 *
 * Named accounts are defined in config/allegro.php under "accounts", in
 * the same way as database connections; options outside "accounts" are
 * shared and can be overridden per account. Sellers connected at runtime
 * (for example per tenant in a SaaS) are addressed with forUser($key),
 * where $key is the token store key of that seller. application() returns
 * a client with a client-credentials token for public resources. Calls
 * made directly on the manager go to the default account.
 *
 * @mixin AllegroClient
 */
final class AllegroManager
{
    /** @var array<string, AllegroClient> */
    private array $clients = [];

    private ?TokenStore $tokenStore = null;

    public function __construct(
        private readonly Container $container,
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * Client of a configured account; the default account when no name is given.
     *
     * @throws InvalidConfiguration When the account is not defined or the application is not configured.
     */
    public function account(?string $name = null): AllegroClient
    {
        $name ??= $this->getDefaultAccount();

        if (isset($this->clients['account:' . $name])) {
            return $this->clients['account:' . $name];
        }

        $options = $this->accountOptions($name);
        $key = is_string($options['token_key'] ?? null) && $options['token_key'] !== '' ? $options['token_key'] : $name;

        return $this->clients['account:' . $name] = $this->make(AllegroConfig::fromArray(self::merge($this->shared(), $options)), $key);
    }

    /**
     * Client of a seller connected at runtime, identified by its token store key (for example "seller-42").
     *
     * @param array<string, mixed> $overrides Options overriding the shared ones, e.g. ['language' => 'cs-CZ'].
     */
    public function forUser(string $key, array $overrides = []): AllegroClient
    {
        if ($overrides !== []) {
            return $this->make(AllegroConfig::fromArray(self::merge($this->shared(), $overrides)), $key);
        }

        return $this->clients['user:' . $key] ??= $this->make($this->sharedConfig(), $key);
    }

    /**
     * Client authenticated with the application itself (client credentials grant).
     *
     * Use it for public resources marked "bearer-token-for-application",
     * such as categories, category parameters, carriers and billing types.
     */
    public function application(): AllegroClient
    {
        if (isset($this->clients['application'])) {
            return $this->clients['application'];
        }

        $config = $this->sharedConfig();

        return $this->clients['application'] = $this->make($config, 'application', true);
    }

    /**
     * URL of the Allegro consent page; send the seller there to connect an account.
     *
     * @param string|null $codeVerifier PKCE verifier from Pkce::generateVerifier(); keep it for exchangeCode().
     * @param list<string>|null $scopes Null uses the configured scopes.
     */
    public function authorizationUrl(?string $state = null, ?string $codeVerifier = null, ?array $scopes = null, ?string $prompt = null): string
    {
        return $this->oauth()->authorizationUrl($state, $codeVerifier, $scopes, $prompt);
    }

    /**
     * Starts the device flow; show the returned user code and verification URI to the seller, then
     * call account(...)->waitForDeviceToken() or forUser(...)->waitForDeviceToken().
     *
     * @param list<string>|null $scopes
     */
    public function startDeviceFlow(?array $scopes = null): DeviceCode
    {
        return $this->oauth()->startDeviceFlow($scopes);
    }

    public function oauth(): OAuthClient
    {
        return new OAuthClient($this->sharedConfig(), $this->http);
    }

    public function getDefaultAccount(): string
    {
        $default = $this->config->get('allegro.default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * Names of the configured accounts.
     *
     * @return list<string>
     */
    public function accountNames(): array
    {
        $accounts = $this->config->get('allegro.accounts');

        if (! is_array($accounts) || $accounts === []) {
            return [$this->getDefaultAccount()];
        }

        return array_map(strval(...), array_keys($accounts));
    }

    /**
     * The token store shared by every account.
     */
    public function tokenStore(): TokenStore
    {
        if ($this->tokenStore !== null) {
            return $this->tokenStore;
        }

        $class = $this->config->get('allegro.token_store');

        if ($this->container->bound(TokenStore::class)) {
            $store = $this->container->make(TokenStore::class);
        } elseif (is_string($class) && $class !== '' && $class !== CacheTokenStore::class) {
            $store = $this->container->make($class);
        } else {
            $config = $this->sharedConfig();
            $store = new CacheTokenStore($this->cache->store($config->cacheStore), $config->cachePrefix . ':' . $config->environment());
        }

        if (! $store instanceof TokenStore) {
            throw InvalidConfiguration::invalidTokenStore(get_debug_type($store));
        }

        return $this->tokenStore = $store;
    }

    /**
     * Forgets resolved clients, for example after the configuration changed.
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];
            $this->tokenStore = null;

            return;
        }

        unset($this->clients['account:' . $name], $this->clients['user:' . $name]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->account(), $method];

        if (! is_callable($callable)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', AllegroClient::class, $method));
        }

        return $callable(...$arguments);
    }

    private function make(AllegroConfig $config, string $key, bool $application = false): AllegroClient
    {
        $logger = null;

        if ($config->logChannel !== null) {
            $logger = $this->container->make('log')->channel($config->logChannel);
        }

        $cache = $this->cache->store($config->cacheStore);
        $tokens = $application ? new CacheTokenStore($cache, $config->cachePrefix . ':' . $config->environment() . ':' . $config->clientId) : $this->tokenStore();

        return new AllegroClient($config, $this->http, new OAuthClient($config, $this->http), $tokens, $cache, $key, $application, $logger);
    }

    private function sharedConfig(): AllegroConfig
    {
        return AllegroConfig::fromArray($this->shared());
    }

    /**
     * @return array<string, mixed>
     */
    private function accountOptions(string $name): array
    {
        $accounts = $this->config->get('allegro.accounts');

        if (! is_array($accounts) || $accounts === []) {
            if ($name !== $this->getDefaultAccount()) {
                throw InvalidConfiguration::unknownAccount($name);
            }

            return [];
        }

        $account = $accounts[$name] ?? null;

        if (! is_array($account)) {
            throw InvalidConfiguration::unknownAccount($name);
        }

        /** @var array<string, mixed> $account */
        return $account;
    }

    /**
     * Applies account options over the shared ones; empty values (unset environment variables) do not override.
     *
     * @param array<string, mixed> $shared
     * @param array<array-key, mixed> $account
     * @return array<string, mixed>
     */
    private static function merge(array $shared, array $account): array
    {
        foreach ($account as $key => $value) {
            if ($value !== null && $value !== '') {
                $shared[(string) $key] = $value;
            }
        }

        return $shared;
    }

    /**
     * Options shared by every account: everything outside "default", "accounts" and "token_store".
     *
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $config = $this->config->get('allegro', []);
        $config = is_array($config) ? $config : [];
        unset($config['default'], $config['accounts'], $config['token_store'], $config['events']);

        /** @var array<string, mixed> $config */
        return $config;
    }
}
