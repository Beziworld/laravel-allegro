<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Enums\FulfillmentStatus;
use Beziworld\Allegro\Enums\OrderStatus;
use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Allegro order (GET /order/checkout-forms/{id}).
 *
 * $status is the buyer-side status (BOUGHT, FILLED_IN,
 * READY_FOR_PROCESSING, CANCELLED); $fulfillmentStatus is the seller-side
 * status changed with Orders::setFulfillmentStatus(), which requires the
 * current $revision.
 */
final readonly class CheckoutForm
{
    /**
     * @param list<LineItem> $lineItems
     * @param list<Payment> $surcharges
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $fulfillmentStatus,
        public ?string $revision,
        public Buyer $buyer,
        public ?Payment $payment,
        public ?Delivery $delivery,
        public ?Invoice $invoice,
        public array $lineItems,
        public ?Money $totalToPay,
        public array $surcharges = [],
        public ?string $messageToSeller = null,
        public ?string $note = null,
        public ?string $marketplaceId = null,
        public ?string $fulfillmentProvider = null,
        public ?string $lineItemsSent = null,
        public ?CarbonImmutable $updatedAt = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            status: Read::string($data, 'status') ?? '',
            fulfillmentStatus: Read::string($data, 'fulfillment.status'),
            revision: Read::string($data, 'revision'),
            buyer: Buyer::fromArray(Read::array($data, 'buyer')),
            payment: Payment::fromArray(Read::arrayOrNull($data, 'payment')),
            delivery: Delivery::fromArray(Read::arrayOrNull($data, 'delivery')),
            invoice: Invoice::fromArray(Read::arrayOrNull($data, 'invoice')),
            lineItems: array_map(LineItem::fromArray(...), Read::rows($data, 'lineItems')),
            totalToPay: Money::fromArray(Read::arrayOrNull($data, 'summary.totalToPay')),
            surcharges: array_values(array_filter(array_map(Payment::fromArray(...), Read::rows($data, 'surcharges')))),
            messageToSeller: Read::string($data, 'messageToSeller'),
            note: Read::string($data, 'note.text'),
            marketplaceId: Read::string($data, 'marketplace.id'),
            fulfillmentProvider: Read::string($data, 'fulfillment.provider.id'),
            lineItemsSent: Read::string($data, 'fulfillment.shipmentSummary.lineItemsSent'),
            updatedAt: Read::date($data, 'updatedAt'),
            raw: $data,
        );
    }

    public function orderStatus(): ?OrderStatus
    {
        return OrderStatus::tryFrom($this->status);
    }

    public function fulfillment(): ?FulfillmentStatus
    {
        return $this->fulfillmentStatus !== null ? FulfillmentStatus::tryFrom($this->fulfillmentStatus) : null;
    }

    /**
     * Whether the buyer finished the checkout, so address and invoice data are final.
     */
    public function isReadyForProcessing(): bool
    {
        return $this->status === OrderStatus::ReadyForProcessing->value;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled->value;
    }

    /**
     * Whether the order is fulfilled by Allegro (One Fulfillment) rather than by the seller.
     */
    public function isFulfilledByAllegro(): bool
    {
        return $this->fulfillmentProvider === 'ALLEGRO';
    }

    public function invoiceRequired(): bool
    {
        return $this->invoice !== null && $this->invoice->required;
    }
}
