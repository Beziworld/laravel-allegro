<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Polling;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\OfferEvent;
use Beziworld\Allegro\Events\OfferEventReceived;

/**
 * Polls GET /sale/offer-events and dispatches OfferEventReceived for every new offer event.
 *
 * The offer journal keeps 24 hours of events, so poll at least daily. On
 * the first run the journal is read from its oldest event. Event types can
 * be narrowed with "allegro.events.offer_types".
 *
 * @extends EventPoller<OfferEvent>
 */
final class OfferEventPoller extends EventPoller
{
    protected function name(): string
    {
        return 'offers';
    }

    protected function fetch(AllegroClient $client, ?string $from, int $limit): array
    {
        $types = $this->config->get('allegro.events.offer_types');

        return $client->offers()->events($from, is_array($types) ? array_values(array_filter($types, is_string(...))) : [], $limit);
    }

    protected function eventId(object $event): string
    {
        return $event->id;
    }

    protected function dispatchEvent(AllegroClient $client, object $event): void
    {
        $this->events->dispatch(new OfferEventReceived($client->key(), $event));
    }
}
