<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Exceptions;

use Beziworld\Allegro\Contracts\TokenStore;

/**
 * Raised when the package configuration is incomplete or inconsistent.
 */
final class InvalidConfiguration extends AllegroException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('Allegro %s is not configured. Set %s in the environment.', $key, $env));
    }

    public static function unknownAccount(string $name): self
    {
        return new self(sprintf('Allegro account "%s" is not defined in config/allegro.php under "accounts".', $name));
    }

    public static function invalidTokenStore(string $class): self
    {
        return new self(sprintf('Allegro token store "%s" must implement %s.', $class, TokenStore::class));
    }
}
