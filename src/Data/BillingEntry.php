<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Entry of the seller's Allegro billing (GET /billing/billing-entries).
 *
 * $value is negative for fees charged to the seller; $typeId identifies
 * the fee type (see Billing::types()), for example "SUC" for the sales
 * commission.
 */
final readonly class BillingEntry
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?CarbonImmutable $occurredAt,
        public ?string $typeId,
        public ?string $typeName,
        public ?Money $value,
        public ?Money $balance,
        public ?string $taxPercentage,
        public ?string $offerId,
        public ?string $offerName,
        public ?string $orderId,
        public ?string $marketplaceId,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            occurredAt: Read::date($data, 'occurredAt'),
            typeId: Read::string($data, 'type.id'),
            typeName: Read::string($data, 'type.name'),
            value: Money::fromArray(Read::arrayOrNull($data, 'value')),
            balance: Money::fromArray(Read::arrayOrNull($data, 'balance')),
            taxPercentage: Read::string($data, 'tax.percentage'),
            offerId: Read::string($data, 'offer.id'),
            offerName: Read::string($data, 'offer.name'),
            orderId: Read::string($data, 'order.id'),
            marketplaceId: Read::string($data, 'marketplaceId'),
            raw: $data,
        );
    }
}
