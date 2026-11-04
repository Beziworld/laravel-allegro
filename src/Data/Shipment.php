<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Shipment created through Wysyłam z Allegro (GET /shipment-management/shipments/{shipmentId}).
 *
 * $waybills lists the waybill of every package in the shipment;
 * $carrier is the carrier id, for example "INPOST" or "DPD".
 */
final readonly class Shipment
{
    /**
     * @param list<string> $waybills
     * @param list<string> $additionalServices
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $carrier,
        public array $waybills,
        public ?string $labelFormat,
        public ?string $deliveryMethodId,
        public ?string $referenceNumber,
        public ?Money $cashOnDelivery,
        public ?Money $insurance,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $canceledAt,
        public bool $pickupAvailable = false,
        public array $additionalServices = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $waybills = [];

        foreach (Read::rows($data, 'packages') as $package) {
            $waybill = Read::string($package, 'waybill');

            if ($waybill !== null) {
                $waybills[] = $waybill;
            }
        }

        return new self(
            id: Read::string($data, 'id') ?? '',
            carrier: Read::string($data, 'carrier'),
            waybills: $waybills,
            labelFormat: Read::string($data, 'labelFormat'),
            deliveryMethodId: Read::string($data, 'deliveryMethodId'),
            referenceNumber: Read::string($data, 'referenceNumber'),
            cashOnDelivery: Money::fromArray(Read::arrayOrNull($data, 'cashOnDelivery')),
            insurance: Money::fromArray(Read::arrayOrNull($data, 'insurance')),
            createdAt: Read::date($data, 'createdDate'),
            canceledAt: Read::date($data, 'canceledDate'),
            pickupAvailable: Read::bool($data, 'pickupAvailable') ?? false,
            additionalServices: Read::strings($data, 'additionalServices'),
            raw: $data,
        );
    }

    public function isCanceled(): bool
    {
        return $this->canceledAt !== null;
    }
}
