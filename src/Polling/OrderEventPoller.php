<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Polling;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\OrderEvent;
use Beziworld\Allegro\Events\OrderEventReceived;

/**
 * Polls GET /order/events and dispatches OrderEventReceived for every new order event.
 *
 * On the first run of an account the cursor starts at the newest event
 * (GET /order/event-stats), so only orders placed from then on are
 * reported; set "allegro.events.start" to "beginning" to replay the last
 * 60 days instead. Event types can be narrowed with "allegro.events.order_types".
 *
 * @extends EventPoller<OrderEvent>
 */
final class OrderEventPoller extends EventPoller
{
    protected function name(): string
    {
        return 'orders';
    }

    protected function fetch(AllegroClient $client, ?string $from, int $limit): array
    {
        $types = $this->config->get('allegro.events.order_types');

        return $client->orders()->events($from, is_array($types) ? array_values(array_filter($types, is_string(...))) : [], $limit);
    }

    protected function eventId(object $event): string
    {
        return $event->id;
    }

    protected function dispatchEvent(AllegroClient $client, object $event): void
    {
        $this->events->dispatch(new OrderEventReceived($client->key(), $event));
    }

    protected function initialCursor(AllegroClient $client): ?string
    {
        return $this->config->get('allegro.events.start') === 'beginning' ? null : $client->orders()->latestEventId();
    }
}
