<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Customer return (beta resource /order/customer-returns).
 *
 * $status is for example CREATED, DISPATCHED, IN_TRANSIT, DELIVERED,
 * FINISHED, REJECTED or COMMISSION_REFUNDED. $parcels lists the return
 * parcels as {waybill, carrierId, transportingWaybill,
 * transportingCarrierId, createdAt}.
 */
final readonly class CustomerReturn
{
    /**
     * @param list<CustomerReturnItem> $items
     * @param list<array{waybill: ?string, carrierId: ?string, transportingWaybill: ?string, transportingCarrierId: ?string, createdAt: ?string}> $parcels
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $orderId,
        public ?string $referenceNumber,
        public ?string $status,
        public ?CarbonImmutable $createdAt,
        public ?string $buyerLogin,
        public ?string $buyerEmail,
        public array $items,
        public array $parcels = [],
        public ?string $rejectionCode = null,
        public ?string $marketplaceId = null,
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
            orderId: Read::string($data, 'orderId'),
            referenceNumber: Read::string($data, 'referenceNumber'),
            status: Read::string($data, 'status'),
            createdAt: Read::date($data, 'createdAt'),
            buyerLogin: Read::string($data, 'buyer.login'),
            buyerEmail: Read::string($data, 'buyer.email'),
            items: array_map(CustomerReturnItem::fromArray(...), Read::rows($data, 'items')),
            parcels: array_map(static fn(array $parcel): array => [
                'waybill' => Read::string($parcel, 'waybill'),
                'carrierId' => Read::string($parcel, 'carrierId'),
                'transportingWaybill' => Read::string($parcel, 'transportingWaybill'),
                'transportingCarrierId' => Read::string($parcel, 'transportingCarrierId'),
                'createdAt' => Read::string($parcel, 'createdAt'),
            ], Read::rows($data, 'parcels')),
            rejectionCode: Read::string($data, 'rejection.code'),
            marketplaceId: Read::string($data, 'marketplaceId'),
            fulfillment: Read::bool($data, 'isFulfillment') ?? false,
            raw: $data,
        );
    }
}
