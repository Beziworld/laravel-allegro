<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Payment of an order (CheckoutFormPaymentReference), also used for surcharges.
 *
 * $type is one of CASH_ON_DELIVERY, WIRE_TRANSFER, ONLINE, SPLIT_PAYMENT
 * or EXTENDED_TERM; $provider one of PAYU, P24, AF, OFFLINE or EPT.
 */
final readonly class Payment
{
    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?string $provider,
        public ?CarbonImmutable $finishedAt,
        public ?Money $paidAmount,
        public ?Money $reconciliation = null,
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
            id: Read::string($data, 'id'),
            type: Read::string($data, 'type'),
            provider: Read::string($data, 'provider'),
            finishedAt: Read::date($data, 'finishedAt'),
            paidAmount: Money::fromArray(Read::arrayOrNull($data, 'paidAmount')),
            reconciliation: Money::fromArray(Read::arrayOrNull($data, 'reconciliation')),
        );
    }

    public function isCashOnDelivery(): bool
    {
        return $this->type === 'CASH_ON_DELIVERY';
    }

    public function isPaid(): bool
    {
        return $this->finishedAt !== null;
    }
}
