<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Line item of an order or an order event.
 *
 * $id identifies the purchase itself and stays the same when payments are
 * merged into a new checkout form; $externalId is the seller's own offer
 * signature (SKU) set in external.id.
 */
final readonly class LineItem
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $offerId,
        public ?string $offerName,
        public ?string $externalId,
        public int $quantity,
        public ?Money $price,
        public ?Money $originalPrice,
        public ?CarbonImmutable $boughtAt,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            offerId: Read::string($data, 'offer.id') ?? '',
            offerName: Read::string($data, 'offer.name'),
            externalId: Read::string($data, 'offer.external.id'),
            quantity: Read::int($data, 'quantity') ?? 0,
            price: Money::fromArray(Read::arrayOrNull($data, 'price')),
            originalPrice: Money::fromArray(Read::arrayOrNull($data, 'originalPrice')),
            boughtAt: Read::date($data, 'boughtAt'),
            raw: $data,
        );
    }
}
