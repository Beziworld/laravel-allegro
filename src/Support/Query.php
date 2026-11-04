<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Support;

use BackedEnum;
use DateTimeInterface;

/**
 * Builds Allegro query strings.
 *
 * Allegro expects repeated parameters for multiple values
 * (type=BOUGHT&type=READY_FOR_PROCESSING) and dotted names for nested
 * filters (publication.status=ACTIVE), which http_build_query() does not
 * produce. Null values are omitted, booleans become "true"/"false" and
 * dates are formatted as ISO 8601 in UTC.
 *
 * @internal
 */
final class Query
{
    /**
     * @param array<string, mixed> $query
     */
    public static function build(array $query): string
    {
        $pairs = [];

        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $item = self::scalar($item);

                if ($item !== null) {
                    $pairs[] = rawurlencode($name) . '=' . rawurlencode($item);
                }
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Appends the query string to the path.
     *
     * @param array<string, mixed> $query
     */
    public static function append(string $path, array $query): string
    {
        $built = self::build($query);

        if ($built === '') {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . $built;
    }

    private static function scalar(mixed $item): ?string
    {
        return match (true) {
            $item === null => null,
            is_bool($item) => $item ? 'true' : 'false',
            $item instanceof DateTimeInterface => \DateTimeImmutable::createFromInterface($item)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            $item instanceof BackedEnum => (string) $item->value,
            is_scalar($item) => (string) $item,
            default => null,
        };
    }
}
