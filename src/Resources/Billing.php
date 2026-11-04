<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\BillingEntry;
use Beziworld\Allegro\Support\Read;

/**
 * Seller billing: fees and refunds charged by Allegro.
 */
final readonly class Billing
{
    public function __construct(private AllegroClient $client) {}

    /**
     * Billing entries, newest first.
     *
     * @param array<string, mixed> $filters e.g. ['occurredAt.gte' => now()->subMonth(), 'type.id' => ['SUC', 'LIS'],
     *     'order.id' => '...', 'offer.id' => '...', 'marketplaceId' => 'allegro-pl'].
     * @param int $limit 1 to 100; offset up to 10 000.
     * @return list<BillingEntry>
     */
    public function entries(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $data = $this->client->request('GET', '/billing/billing-entries', array_merge($filters, [
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, min(10000, $offset)),
        ]));

        return array_map(BillingEntry::fromArray(...), Read::rows($data, 'billingEntries'));
    }

    /**
     * Billing entry types with their ids and descriptions.
     *
     * @return list<array<array-key, mixed>>
     */
    public function types(): array
    {
        $data = $this->client->request('GET', '/billing/billing-types');

        return array_values(array_filter($data, is_array(...)));
    }
}
