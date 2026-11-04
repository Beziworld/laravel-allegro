<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\CommandReport;
use Beziworld\Allegro\Data\CommandTask;
use Beziworld\Allegro\Data\Money;
use Beziworld\Allegro\Data\OfferEvent;
use Beziworld\Allegro\Data\OfferList;
use Beziworld\Allegro\Data\OfferSummary;
use Beziworld\Allegro\Data\ProductOffer;
use Beziworld\Allegro\Data\ProductOfferResult;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Support\Read;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Offers: listing, product-offers (create, edit, read), batch price and quantity commands and the offer event journal.
 */
final readonly class Offers
{
    public function __construct(private AllegroClient $client) {}

    /**
     * One page of the seller's offers (GET /sale/offers).
     *
     * @param array<string, mixed> $filters API filters, e.g. ['publication.status' => ['ACTIVE'], 'external.id' => 'SKU-1',
     *     'name' => 'phrase', 'category.id' => '257931', 'sort' => '-stock.sold'].
     * @param int $limit 1 to 1000.
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): OfferList
    {
        return OfferList::fromArray($this->client->request('GET', '/sale/offers', array_merge($filters, [
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ])));
    }

    /**
     * Iterates over every matching offer, page by page.
     *
     * @param array<string, mixed> $filters
     * @return Generator<int, OfferSummary>
     */
    public function all(array $filters = [], int $pageSize = 1000): Generator
    {
        $pageSize = max(1, min(1000, $pageSize));

        for ($offset = 0; ; $offset += $pageSize) {
            $page = $this->list($filters, $pageSize, $offset);

            yield from $page->offers;

            if (count($page->offers) < $pageSize || $offset + $pageSize >= $page->totalCount) {
                return;
            }
        }
    }

    /**
     * Full offer (GET /sale/product-offers/{offerId}).
     */
    public function get(string $offerId): ProductOffer
    {
        return ProductOffer::fromArray($this->client->request('GET', '/sale/product-offers/' . rawurlencode($offerId)));
    }

    /**
     * Selected parts of an offer, e.g. ['stock', 'price'] (GET /sale/product-offers/{offerId}/parts).
     *
     * @param list<string> $include
     * @return array<array-key, mixed>
     */
    public function parts(string $offerId, array $include): array
    {
        return $this->client->request('GET', '/sale/product-offers/' . rawurlencode($offerId) . '/parts', ['include' => $include]);
    }

    /**
     * Creates an offer (POST /sale/product-offers, body SaleProductOfferRequestV1).
     *
     * @param array<array-key, mixed> $offer e.g. ['productSet' => [['product' => ['id' => '...']]], 'sellingMode' => ['price' => [...]],
     *     'stock' => ['available' => 10], 'publication' => ['status' => 'ACTIVE'], 'external' => ['id' => 'SKU-1']].
     */
    public function create(array $offer): ProductOfferResult
    {
        return $this->result($this->client->send('POST', '/sale/product-offers', [], $offer));
    }

    /**
     * Changes selected fields of an offer (PATCH /sale/product-offers/{offerId}).
     *
     * @param array<array-key, mixed> $changes e.g. ['sellingMode' => ['price' => ['amount' => '99.90', 'currency' => 'PLN']], 'stock' => ['available' => 5]].
     */
    public function edit(string $offerId, array $changes): ProductOfferResult
    {
        return $this->result($this->client->send('PATCH', '/sale/product-offers/' . rawurlencode($offerId), [], $changes));
    }

    /**
     * Waits until an asynchronous create or edit (HTTP 202) is processed and returns the resulting offer.
     *
     * @param ProductOfferResult|string $operation The result of create()/edit() or the Location URL of the operation.
     *
     * @throws ApiException When the operation does not finish within the configured polling attempts.
     */
    public function waitForOperation(ProductOfferResult|string $operation): ProductOffer
    {
        if ($operation instanceof ProductOfferResult && ! $operation->isPending()) {
            return $operation->offer;
        }

        $url = $operation instanceof ProductOfferResult ? (string) $operation->operationUrl : $operation;
        $retryAfter = $operation instanceof ProductOfferResult ? $operation->retryAfter : null;
        $path = self::path($url);
        $offerId = preg_match('#/sale/product-offers/([^/]+)/operations/#', $path, $matches) === 1 ? rawurldecode($matches[1]) : null;

        if ($offerId === null && $operation instanceof ProductOfferResult && $operation->offer->id !== '') {
            $offerId = $operation->offer->id;
        }

        for ($attempt = 1; $attempt <= $this->client->config()->pollAttempts; $attempt++) {
            $this->client->pause($retryAfter);
            $response = $this->client->send('GET', $path, followRedirects: false);

            if ($response->status() === 303 || $response->status() === 200) {
                $location = $response->header('Location');

                if ($offerId === null && preg_match('#/sale/product-offers/([^/?]+)#', $location, $matches) === 1) {
                    $offerId = rawurldecode($matches[1]);
                }

                return $this->get($offerId ?? throw ApiException::unexpected('Allegro finished the offer operation without pointing to the offer.', $response->status(), $this->client->lastTraceId()));
            }

            $retryAfter = self::retryAfter($response);
        }

        throw ApiException::timeout($path);
    }

    /**
     * Deletes a draft offer (only INACTIVE offers that were never published can be deleted).
     */
    public function delete(string $offerId): void
    {
        $this->client->send('DELETE', '/sale/offers/' . rawurlencode($offerId));
    }

    /**
     * Sets the buy-now price of the offers through a batch command (PUT /sale/offer-price-change-commands/{commandId}).
     *
     * @param list<string>|string $offerIds
     * @param string|null $commandId UUID; generated when null. Reusing an id returns HTTP 409.
     */
    public function changePrice(array|string $offerIds, Money $price, ?string $commandId = null, ?string $marketplaceId = null): CommandReport
    {
        return $this->priceChangeCommand(array_filter([
            'type' => 'FIXED_PRICE',
            'price' => $price->toArray(),
            'marketplaceId' => $marketplaceId,
        ], static fn(mixed $value): bool => $value !== null), (array) $offerIds, $commandId);
    }

    /**
     * Sends a price change command with any modification type.
     *
     * @param array<string, mixed> $modification e.g. ['type' => 'INCREASE_PERCENTAGE', 'percentage' => '5.00']
     *     or ['type' => 'DECREASE_PRICE', 'value' => ['amount' => '2.00', 'currency' => 'PLN']].
     * @param list<string> $offerIds
     */
    public function priceChangeCommand(array $modification, array $offerIds, ?string $commandId = null): CommandReport
    {
        return $this->command('offer-price-change-commands', ['modification' => $modification, 'offerCriteria' => self::criteria($offerIds)], $commandId);
    }

    public function priceChangeReport(string $commandId): CommandReport
    {
        return CommandReport::fromArray($this->client->request('GET', '/sale/offer-price-change-commands/' . rawurlencode($commandId)));
    }

    /**
     * Per-offer results of a price change command.
     *
     * @return list<CommandTask>
     */
    public function priceChangeTasks(string $commandId, int $limit = 100, int $offset = 0): array
    {
        return $this->tasks('offer-price-change-commands', $commandId, $limit, $offset);
    }

    /**
     * Changes the stock of the offers through a batch command (PUT /sale/offer-quantity-change-commands/{commandId}).
     *
     * @param list<string>|string $offerIds
     * @param 'FIXED'|'GAIN' $changeType FIXED sets the quantity, GAIN adds to (or, with a negative value, subtracts from) it.
     */
    public function changeQuantity(array|string $offerIds, int $quantity, string $changeType = 'FIXED', ?string $commandId = null): CommandReport
    {
        return $this->command('offer-quantity-change-commands', [
            'modification' => ['changeType' => $changeType, 'value' => $quantity],
            'offerCriteria' => self::criteria((array) $offerIds),
        ], $commandId);
    }

    public function quantityChangeReport(string $commandId): CommandReport
    {
        return CommandReport::fromArray($this->client->request('GET', '/sale/offer-quantity-change-commands/' . rawurlencode($commandId)));
    }

    /**
     * Per-offer results of a quantity change command.
     *
     * @return list<CommandTask>
     */
    public function quantityChangeTasks(string $commandId, int $limit = 100, int $offset = 0): array
    {
        return $this->tasks('offer-quantity-change-commands', $commandId, $limit, $offset);
    }

    /**
     * Offer events (last 24 hours) after the given event id.
     *
     * @param list<string> $types e.g. ['OFFER_STOCK_CHANGED', 'OFFER_ENDED'].
     * @param int $limit 1 to 1000.
     * @return list<OfferEvent>
     */
    public function events(?string $from = null, array $types = [], int $limit = 100): array
    {
        $data = $this->client->request('GET', '/sale/offer-events', [
            'from' => $from,
            'type' => $types,
            'limit' => max(1, min(1000, $limit)),
        ]);

        return array_map(OfferEvent::fromArray(...), Read::rows($data, 'offerEvents'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function command(string $resource, array $body, ?string $commandId): CommandReport
    {
        $commandId ??= (string) Str::uuid();
        $data = $this->client->request('PUT', '/sale/' . $resource . '/' . rawurlencode($commandId), [], $body);

        return CommandReport::fromArray(['id' => $commandId] + $data);
    }

    /**
     * @return list<CommandTask>
     */
    private function tasks(string $resource, string $commandId, int $limit, int $offset): array
    {
        $data = $this->client->request('GET', '/sale/' . $resource . '/' . rawurlencode($commandId) . '/tasks', [
            'limit' => max(1, min(1000, $limit)),
            'offset' => max(0, $offset),
        ]);

        return array_map(CommandTask::fromArray(...), Read::rows($data, 'tasks'));
    }

    private function result(Response $response): ProductOfferResult
    {
        $data = $response->json();
        $location = $response->header('Location');

        return new ProductOfferResult(
            httpStatus: $response->status(),
            offer: ProductOffer::fromArray(is_array($data) ? $data : []),
            operationUrl: $location !== '' ? $location : null,
            retryAfter: self::retryAfter($response),
        );
    }

    /**
     * @param list<string> $offerIds
     * @return list<array{type: string, offers: list<array{id: string}>}>
     */
    private static function criteria(array $offerIds): array
    {
        return [['type' => 'CONTAINS_OFFERS', 'offers' => array_map(static fn(string $id): array => ['id' => $id], $offerIds)]];
    }

    private static function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Path and query of an operation URL, so the request always goes to the configured API host with the account token.
     */
    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) ? $path : '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
