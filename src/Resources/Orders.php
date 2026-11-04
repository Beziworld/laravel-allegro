<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\Carrier;
use Beziworld\Allegro\Data\CheckoutForm;
use Beziworld\Allegro\Data\CheckoutFormList;
use Beziworld\Allegro\Data\OrderEvent;
use Beziworld\Allegro\Data\OrderShipment;
use Beziworld\Allegro\Enums\FulfillmentStatus;
use Beziworld\Allegro\Support\Read;
use Generator;

/**
 * Orders: event journal, checkout forms, fulfillment status, tracking numbers and invoices.
 */
final readonly class Orders
{
    /**
     * Allegro refuses order list requests where offset + limit exceeds this value.
     */
    public const int MAX_LIST_WINDOW = 10000;

    public function __construct(private AllegroClient $client) {}

    /**
     * Order events (last 60 days, oldest first) after the given event id.
     *
     * @param string|null $from Id of the last processed event; null starts at the oldest available event.
     * @param list<string> $types Event types to include, e.g. [OrderEvent::READY_FOR_PROCESSING].
     * @param int $limit 1 to 1000.
     * @return list<OrderEvent>
     */
    public function events(?string $from = null, array $types = [], int $limit = 100): array
    {
        $data = $this->client->request('GET', '/order/events', [
            'from' => $from,
            'type' => $types,
            'limit' => self::clamp($limit, 1000),
        ]);

        return array_map(OrderEvent::fromArray(...), Read::rows($data, 'events'));
    }

    /**
     * Id of the newest order event, used to start polling from now; null when the seller has no events.
     */
    public function latestEventId(): ?string
    {
        return Read::string($this->client->request('GET', '/order/event-stats'), 'latestEvent.id');
    }

    /**
     * One page of the seller's orders (last 12 months).
     *
     * @param array<string, mixed> $filters API filters, e.g. ['status' => 'READY_FOR_PROCESSING', 'fulfillment.status' => 'NEW',
     *     'lineItems.boughtAt.gte' => now()->subDay(), 'sort' => '-lineItems.boughtAt'].
     * @param int $limit 1 to 100.
     */
    public function checkoutForms(array $filters = [], int $limit = 100, int $offset = 0): CheckoutFormList
    {
        $data = $this->client->request('GET', '/order/checkout-forms', array_merge($filters, [
            'limit' => self::clamp($limit, 100),
            'offset' => max(0, $offset),
        ]));

        return CheckoutFormList::fromArray($data);
    }

    /**
     * Iterates over every matching order, page by page, within the 10 000 orders window of the API.
     *
     * @param array<string, mixed> $filters
     * @return Generator<int, CheckoutForm>
     */
    public function allCheckoutForms(array $filters = [], int $pageSize = 100): Generator
    {
        $pageSize = self::clamp($pageSize, 100);

        for ($offset = 0; $offset + $pageSize <= self::MAX_LIST_WINDOW; $offset += $pageSize) {
            $page = $this->checkoutForms($filters, $pageSize, $offset);

            yield from $page->checkoutForms;

            if (count($page->checkoutForms) < $pageSize || $offset + $pageSize >= $page->totalCount) {
                return;
            }
        }
    }

    public function checkoutForm(string $checkoutFormId): CheckoutForm
    {
        return CheckoutForm::fromArray($this->client->request('GET', '/order/checkout-forms/' . rawurlencode($checkoutFormId)));
    }

    /**
     * Changes the seller-side status of an order.
     *
     * @param string|null $revision checkoutForm.revision from the order or the event; when given, Allegro answers
     *     HTTP 409 (ApiException::isConflict()) if the order changed in the meantime.
     */
    public function setFulfillmentStatus(string $checkoutFormId, FulfillmentStatus|string $status, ?string $revision = null): void
    {
        $this->client->send(
            'PUT',
            '/order/checkout-forms/' . rawurlencode($checkoutFormId) . '/fulfillment',
            ['checkoutForm.revision' => $revision],
            ['status' => $status instanceof FulfillmentStatus ? $status->value : $status],
        );
    }

    /**
     * Tracking numbers added to the order.
     *
     * @return list<OrderShipment>
     */
    public function shipments(string $checkoutFormId): array
    {
        $data = $this->client->request('GET', '/order/checkout-forms/' . rawurlencode($checkoutFormId) . '/shipments');

        return array_map(OrderShipment::fromArray(...), Read::rows($data, 'shipments'));
    }

    /**
     * Adds a tracking number (waybill) to the order.
     *
     * @param string $carrierId Id from carriers(), e.g. "INPOST", "DPD"; "OTHER" requires $carrierName.
     * @param list<string> $lineItemIds Line items in the parcel; empty means the whole order.
     */
    public function addTrackingNumber(string $checkoutFormId, string $carrierId, string $waybill, ?string $carrierName = null, array $lineItemIds = []): OrderShipment
    {
        $body = array_filter([
            'carrierId' => $carrierId,
            'waybill' => $waybill,
            'carrierName' => $carrierName,
            'lineItems' => $lineItemIds !== [] ? array_map(static fn(string $id): array => ['id' => $id], $lineItemIds) : null,
        ], static fn(mixed $value): bool => $value !== null);

        return OrderShipment::fromArray($this->client->request('POST', '/order/checkout-forms/' . rawurlencode($checkoutFormId) . '/shipments', [], $body));
    }

    /**
     * Carriers accepted by addTrackingNumber().
     *
     * @return list<Carrier>
     */
    public function carriers(): array
    {
        return array_map(Carrier::fromArray(...), Read::rows($this->client->request('GET', '/order/carriers'), 'carriers'));
    }

    /**
     * Tracking history of parcels of one carrier (GET /order/carriers/{carrierId}/tracking).
     *
     * @param list<string>|string $waybills
     * @return array<array-key, mixed> {carrierId, waybills: [{waybill, trackingDetails: {statuses, createdAt, updatedAt}}]}
     */
    public function tracking(string $carrierId, array|string $waybills): array
    {
        return $this->client->request('GET', '/order/carriers/' . rawurlencode($carrierId) . '/tracking', ['waybill' => (array) $waybills]);
    }

    /**
     * Billing documents (invoices and links) attached to the order.
     *
     * @return array<array-key, mixed> {invoices: [{id, invoiceNumber, createdAt, file}], links: [{id, url, createdAt}], hasExternalInvoices}
     */
    public function billingDocuments(string $checkoutFormId): array
    {
        return $this->client->request('GET', '/order/checkout-forms/' . rawurlencode($checkoutFormId) . '/invoices');
    }

    /**
     * Uploads an invoice file (PDF) with its number and attaches it to the order; returns the document id.
     */
    public function uploadInvoice(string $orderId, string $contents, ?string $invoiceNumber = null, string $fileName = 'invoice.pdf', string $contentType = 'application/pdf'): string
    {
        $response = $this->client->sendMultipart(
            '/order/' . rawurlencode($orderId) . '/billing-documents/files',
            'file',
            $contents,
            $fileName,
            $contentType,
            $invoiceNumber !== null ? ['invoiceNumber' => $invoiceNumber] : [],
        );

        $data = $response->json();

        return is_array($data) ? Read::string($data, 'id') ?? '' : '';
    }

    /**
     * Shares a link to a billing document (receipt or invoice) with the buyer; up to 10 links per order.
     */
    public function addBillingDocumentLink(string $orderId, string $url): void
    {
        $this->client->send('POST', '/order/' . rawurlencode($orderId) . '/billing-documents/links', [], ['url' => $url]);
    }

    private static function clamp(int $value, int $max): int
    {
        return max(1, min($max, $value));
    }
}
