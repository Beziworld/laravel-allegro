<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Entry of the offer event journal (GET /sale/offer-events, last 24 hours).
 *
 * $type is for example OFFER_ACTIVATED, OFFER_CHANGED, OFFER_ENDED,
 * OFFER_STOCK_CHANGED, OFFER_PRICE_CHANGED or OFFER_ARCHIVED; the
 * type-specific payload is in $raw.
 */
final readonly class OfferEvent
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?CarbonImmutable $occurredAt,
        public ?string $offerId,
        public ?string $externalId,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            type: Read::string($data, 'type') ?? '',
            occurredAt: Read::date($data, 'occurredAt'),
            offerId: Read::string($data, 'offer.id'),
            externalId: Read::string($data, 'offer.external.id'),
            raw: $data,
        );
    }
}
