<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Events;

use Beziworld\Allegro\Data\OfferEvent;

/**
 * Dispatched by OfferEventPoller for every new entry of the offer event journal.
 *
 * $account is the token store key of the seller account. Listeners should
 * be idempotent, for the same reasons as for OrderEventReceived.
 */
final readonly class OfferEventReceived
{
    public function __construct(
        public string $account,
        public OfferEvent $event,
    ) {}
}
