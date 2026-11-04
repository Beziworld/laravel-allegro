<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Entry of the order event journal (GET /order/events).
 *
 * $type is one of BOUGHT, FILLED_IN, READY_FOR_PROCESSING,
 * BUYER_CANCELLED, FULFILLMENT_STATUS_CHANGED, BUYER_MODIFIED or
 * AUTO_CANCELLED. Events can repeat and arrive out of order, so key the
 * processing on $checkoutFormId and fetch the current order before acting.
 */
final readonly class OrderEvent
{
    public const string BOUGHT = 'BOUGHT';

    public const string FILLED_IN = 'FILLED_IN';

    public const string READY_FOR_PROCESSING = 'READY_FOR_PROCESSING';

    public const string BUYER_CANCELLED = 'BUYER_CANCELLED';

    public const string FULFILLMENT_STATUS_CHANGED = 'FULFILLMENT_STATUS_CHANGED';

    public const string BUYER_MODIFIED = 'BUYER_MODIFIED';

    public const string AUTO_CANCELLED = 'AUTO_CANCELLED';

    /**
     * @param list<LineItem> $lineItems
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?CarbonImmutable $occurredAt,
        public string $checkoutFormId,
        public ?string $revision,
        public ?string $sellerId,
        public ?Buyer $buyer,
        public array $lineItems,
        public ?string $marketplaceId,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $buyer = Read::arrayOrNull($data, 'order.buyer');

        return new self(
            id: Read::string($data, 'id') ?? '',
            type: Read::string($data, 'type') ?? '',
            occurredAt: Read::date($data, 'occurredAt'),
            checkoutFormId: Read::string($data, 'order.checkoutForm.id') ?? '',
            revision: Read::string($data, 'order.checkoutForm.revision'),
            sellerId: Read::string($data, 'order.seller.id'),
            buyer: $buyer !== null ? Buyer::fromArray($buyer) : null,
            lineItems: array_map(LineItem::fromArray(...), Read::rows($data, 'order.lineItems')),
            marketplaceId: Read::string($data, 'order.marketplace.id'),
            raw: $data,
        );
    }

    public function isCancellation(): bool
    {
        return $this->type === self::BUYER_CANCELLED || $this->type === self::AUTO_CANCELLED;
    }
}
