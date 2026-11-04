<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Type-safe accessors for decoded JSON payloads.
 *
 * Allegro always includes null-valued fields, so every accessor treats a
 * missing key and an explicit null in the same way. Keys may use dot
 * notation to reach nested values.
 *
 * @internal
 */
final class Read
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $found = data_get($data, $key);

        return is_scalar($found) && ! is_bool($found) && (string) $found !== '' ? (string) $found : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $found = data_get($data, $key);

        return is_numeric($found) ? (int) $found : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $found = data_get($data, $key);

        return is_bool($found) ? $found : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $found = data_get($data, $key);

        return is_array($found) ? $found : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|null
     */
    public static function arrayOrNull(array $data, string $key): ?array
    {
        $found = data_get($data, $key);

        return is_array($found) ? $found : null;
    }

    /**
     * Nested arrays found under the key, in order; scalar entries are skipped.
     *
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key), is_array(...)));
    }

    /**
     * Scalar entries of a list, cast to strings.
     *
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $values = [];

        foreach (self::array($data, $key) as $entry) {
            if (is_scalar($entry) && ! is_bool($entry)) {
                $values[] = (string) $entry;
            }
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function date(array $data, string $key): ?CarbonImmutable
    {
        $found = self::string($data, $key);

        if ($found === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($found);
        } catch (Throwable) {
            return null;
        }
    }
}
