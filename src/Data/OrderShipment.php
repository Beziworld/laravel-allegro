<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Tracking number attached to an order (POST/GET /order/checkout-forms/{id}/shipments).
 */
final readonly class OrderShipment
{
    /**
     * @param list<string> $lineItemIds
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $id,
        public string $waybill,
        public string $carrierId,
        public ?string $carrierName,
        public ?CarbonImmutable $createdAt,
        public array $lineItemIds = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id'),
            waybill: Read::string($data, 'waybill') ?? '',
            carrierId: Read::string($data, 'carrierId') ?? '',
            carrierName: Read::string($data, 'carrierName'),
            createdAt: Read::date($data, 'createdAt'),
            lineItemIds: array_values(array_filter(array_map(static fn(array $item): ?string => Read::string($item, 'id'), Read::rows($data, 'lineItems')), static fn(?string $id): bool => $id !== null)),
            raw: $data,
        );
    }
}
