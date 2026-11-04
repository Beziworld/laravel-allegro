<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Contracts;

use Beziworld\Allegro\Data\AccessToken;

/**
 * Persistent storage of seller tokens.
 *
 * The key is the account name of a configured account or the key passed
 * to Allegro::forUser(), for example a seller or tenant id. Allegro
 * refresh tokens are single-use: every refresh returns a new one, which
 * put() must persist immediately, otherwise the seller has to authorize
 * the application again. Refreshing is serialized by the package with a
 * cache lock, so implementations do not need their own locking.
 *
 * The default implementation keeps tokens in the Laravel cache; bind your
 * own implementation (for example an Eloquent model per seller) in
 * config/allegro.php under "token_store" or in the service container.
 */
interface TokenStore
{
    public function get(string $key): ?AccessToken;

    public function put(string $key, AccessToken $token): void;

    public function forget(string $key): void;
}
