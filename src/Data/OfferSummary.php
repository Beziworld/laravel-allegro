<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Offer as listed by GET /sale/offers (OfferListingDto).
 *
 * $status is the publication status: INACTIVE, ACTIVATING, ACTIVE or ENDED.
 */
final readonly class OfferSummary
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $categoryId,
        public ?string $status,
        public ?string $format,
        public ?Money $price,
        public ?int $stockAvailable,
        public ?int $stockSold,
        public ?string $externalId,
        public ?string $primaryImageUrl = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public bool $fulfillment = false,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            name: Read::string($data, 'name') ?? '',
            categoryId: Read::string($data, 'category.id'),
            status: Read::string($data, 'publication.status'),
            format: Read::string($data, 'sellingMode.format'),
            price: Money::fromArray(Read::arrayOrNull($data, 'sellingMode.price')),
            stockAvailable: Read::int($data, 'stock.available'),
            stockSold: Read::int($data, 'stock.sold'),
            externalId: Read::string($data, 'external.id'),
            primaryImageUrl: Read::string($data, 'primaryImage.url'),
            startedAt: Read::date($data, 'publication.startedAt'),
            endedAt: Read::date($data, 'publication.endedAt'),
            fulfillment: Read::bool($data, 'isFulfillment') ?? false,
            raw: $data,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
