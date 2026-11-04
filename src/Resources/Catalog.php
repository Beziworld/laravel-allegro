<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\Category;
use Beziworld\Allegro\Support\Read;

/**
 * Categories, category parameters and the Allegro product catalogue.
 *
 * Category resources accept an application token, so they also work
 * through Allegro::application(); product search requires a seller token.
 */
final readonly class Catalog
{
    public function __construct(private AllegroClient $client) {}

    /**
     * Subcategories of a category; the main categories when $parentId is null.
     *
     * @return list<Category>
     */
    public function categories(?string $parentId = null): array
    {
        return array_map(Category::fromArray(...), Read::rows($this->client->request('GET', '/sale/categories', ['parent.id' => $parentId]), 'categories'));
    }

    public function category(string $categoryId): Category
    {
        return Category::fromArray($this->client->request('GET', '/sale/categories/' . rawurlencode($categoryId)));
    }

    /**
     * Offer parameters of a category: id, name, type, required, dictionary, restrictions.
     *
     * @return list<array<array-key, mixed>>
     */
    public function parameters(string $categoryId): array
    {
        return Read::rows($this->client->request('GET', '/sale/categories/' . rawurlencode($categoryId) . '/parameters'), 'parameters');
    }

    /**
     * Product parameters of a category, used when proposing a new product.
     *
     * @return list<array<array-key, mixed>>
     */
    public function productParameters(string $categoryId): array
    {
        return Read::rows($this->client->request('GET', '/sale/categories/' . rawurlencode($categoryId) . '/product-parameters'), 'parameters');
    }

    /**
     * Categories suggested for a product name (limited to 5 requests per second).
     *
     * @return list<array<array-key, mixed>> Each entry: id, name, parent (nested).
     */
    public function matchingCategories(string $name): array
    {
        return Read::rows($this->client->request('GET', '/sale/matching-categories', ['name' => $name]), 'matchingCategories');
    }

    /**
     * Searches the product catalogue by phrase, EAN/GTIN or MPN.
     *
     * @param 'GTIN'|'MPN'|null $mode Interpret the phrase as a GTIN (EAN) or MPN; null searches by text.
     * @param array<string, mixed> $filters Additional parameters, e.g. ['page.id' => $nextPageId, 'language' => 'pl-PL'].
     * @return array<array-key, mixed> {products: [...], categories, filters, nextPage: {id}}
     */
    public function searchProducts(string $phrase, ?string $mode = null, ?string $categoryId = null, array $filters = []): array
    {
        return $this->client->request('GET', '/sale/products', array_merge(['phrase' => $phrase, 'mode' => $mode, 'category.id' => $categoryId], $filters));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function product(string $productId, ?string $categoryId = null): array
    {
        return $this->client->request('GET', '/sale/products/' . rawurlencode($productId), ['category.id' => $categoryId]);
    }

    /**
     * Category change journal (new, renamed, moved or deleted categories).
     *
     * @param list<string> $types
     * @return list<array<array-key, mixed>>
     */
    public function categoryEvents(?string $from = null, array $types = [], int $limit = 100): array
    {
        return Read::rows($this->client->request('GET', '/sale/category-events', [
            'from' => $from,
            'type' => $types,
            'limit' => max(1, min(1000, $limit)),
        ]), 'events');
    }
}
