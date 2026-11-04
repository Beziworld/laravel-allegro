<?php

declare(strict_types=1);

namespace Beziworld\Allegro\OAuth;

use InvalidArgumentException;

/**
 * Proof Key for Code Exchange (RFC 7636) helpers for the authorization code flow.
 *
 * Generate a verifier, keep it in the session, pass it to
 * Allegro::authorizationUrl() and again to exchangeCode() in the callback.
 */
final class Pkce
{
    /**
     * Random URL-safe code verifier of the given length (43 to 128 characters).
     */
    public static function generateVerifier(int $length = 64): string
    {
        if ($length < 43 || $length > 128) {
            throw new InvalidArgumentException('A PKCE code verifier must be 43 to 128 characters long.');
        }

        $verifier = self::base64Url(random_bytes($length));

        return substr($verifier, 0, $length);
    }

    /**
     * S256 code challenge of the verifier: base64url(sha256(verifier)) without padding.
     */
    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
