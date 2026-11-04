<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Carrier accepted when adding a tracking number (GET /order/carriers).
 *
 * Use "OTHER" together with a carrier name for carriers outside the list.
 */
final readonly class Carrier
{
    public function __construct(
        public string $id,
        public ?string $name,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Read::string($data, 'id') ?? '', Read::string($data, 'name'));
    }
}
