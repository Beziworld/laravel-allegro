<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Events;

use Beziworld\Allegro\Data\OrderEvent;

/**
 * Dispatched by OrderEventPoller for every new entry of the order event journal.
 *
 * $account is the token store key of the seller account (the account
 * name or the key passed to Allegro::forUser()). Each event is
 * dispatched once under normal operation, but a crash between dispatching
 * and saving the cursor can repeat it, so listeners should be idempotent
 * (key them on $event->id or $event->checkoutFormId).
 */
final readonly class OrderEventReceived
{
    public function __construct(
        public string $account,
        public OrderEvent $event,
    ) {}
}
