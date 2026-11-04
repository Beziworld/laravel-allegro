<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\CustomerReturn;
use Beziworld\Allegro\Support\Read;

/**
 * Customer returns (/order/customer-returns), served with the beta media type application/vnd.allegro.beta.v1+json.
 */
final readonly class CustomerReturns
{
    public function __construct(private AllegroClient $client) {}

    /**
     * Customer returns matching the filters.
     *
     * @param array<string, mixed> $filters e.g. ['orderId' => '...', 'status' => 'DELIVERED', 'buyer.login' => '...',
     *     'createdAt.gte' => now()->subWeek(), 'from' => $lastReturnId].
     * @param int $limit 1 to 1000.
     * @return list<CustomerReturn>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $data = $this->client->request('GET', '/order/customer-returns', array_merge($filters, [
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ]), mediaType: AllegroClient::BETA_MEDIA_TYPE);

        return array_map(CustomerReturn::fromArray(...), Read::rows($data, 'customerReturns'));
    }

    public function get(string $customerReturnId): CustomerReturn
    {
        return CustomerReturn::fromArray($this->client->request('GET', '/order/customer-returns/' . rawurlencode($customerReturnId), mediaType: AllegroClient::BETA_MEDIA_TYPE));
    }

    /**
     * Return form filled in by the buyer, as a PDF.
     */
    public function form(string $customerReturnId): string
    {
        return $this->client->send('GET', '/order/customer-returns/' . rawurlencode($customerReturnId) . '/files', accept: 'application/pdf')->body();
    }

    /**
     * Rejects the refund of a return.
     *
     * @param string $code REFUND_REJECTED, NEW_ITEM_SENT, ITEM_FIXED, MISSING_PART_SENT, ITEM_MISMATCH, BUSINESS_PURCHASE or NO_RETURN_RIGHT.
     */
    public function rejectRefund(string $customerReturnId, string $code, ?string $reason = null): CustomerReturn
    {
        $data = $this->client->request(
            'POST',
            '/order/customer-returns/' . rawurlencode($customerReturnId) . '/rejection',
            [],
            ['rejection' => array_filter(['code' => $code, 'reason' => $reason], static fn(?string $value): bool => $value !== null)],
            AllegroClient::BETA_MEDIA_TYPE,
        );

        return CustomerReturn::fromArray($data);
    }
}
