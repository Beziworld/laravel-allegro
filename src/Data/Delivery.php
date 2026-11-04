<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Delivery of an order (CheckoutFormDeliveryReference).
 *
 * $methodId identifies the Allegro delivery method chosen by the buyer;
 * the pickup point fields are filled for parcel lockers and pickup points.
 */
final readonly class Delivery
{
    public function __construct(
        public ?Address $address,
        public ?string $methodId,
        public ?string $methodName,
        public ?Money $cost,
        public ?string $pickupPointId = null,
        public ?string $pickupPointName = null,
        public ?string $pickupPointDescription = null,
        public ?Address $pickupPointAddress = null,
        public bool $smart = false,
        public ?int $calculatedNumberOfPackages = null,
        public ?CarbonImmutable $timeFrom = null,
        public ?CarbonImmutable $timeTo = null,
        public ?CarbonImmutable $dispatchFrom = null,
        public ?CarbonImmutable $dispatchTo = null,
    ) {}

    /**
     * @param array<array-key, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        return new self(
            address: Address::fromArray(Read::arrayOrNull($data, 'address')),
            methodId: Read::string($data, 'method.id'),
            methodName: Read::string($data, 'method.name'),
            cost: Money::fromArray(Read::arrayOrNull($data, 'cost')),
            pickupPointId: Read::string($data, 'pickupPoint.id'),
            pickupPointName: Read::string($data, 'pickupPoint.name'),
            pickupPointDescription: Read::string($data, 'pickupPoint.description'),
            pickupPointAddress: Address::fromArray(Read::arrayOrNull($data, 'pickupPoint.address')),
            smart: Read::bool($data, 'smart') ?? false,
            calculatedNumberOfPackages: Read::int($data, 'calculatedNumberOfPackages'),
            timeFrom: Read::date($data, 'time.from'),
            timeTo: Read::date($data, 'time.to'),
            dispatchFrom: Read::date($data, 'time.dispatch.from'),
            dispatchTo: Read::date($data, 'time.dispatch.to'),
        );
    }

    public function isPickupPoint(): bool
    {
        return $this->pickupPointId !== null;
    }
}
