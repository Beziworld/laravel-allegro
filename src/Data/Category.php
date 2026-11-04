<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Allegro category (CategoryDto). Offers can only be listed in leaf categories.
 */
final readonly class Category
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId,
        public bool $leaf,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parent = data_get($data, 'parent');

        return new self(
            id: Read::string($data, 'id') ?? '',
            name: Read::string($data, 'name') ?? '',
            parentId: is_array($parent) ? Read::string($parent, 'id') : null,
            leaf: Read::bool($data, 'leaf') ?? false,
            raw: $data,
        );
    }
}
