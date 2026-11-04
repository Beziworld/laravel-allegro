<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Full offer (SaleProductOfferResponseV1) returned by /sale/product-offers.
 *
 * The most used fields are typed; everything else (parameters,
 * description, delivery, after-sales services, validation) is available
 * in $raw with the field names of the API.
 */
final readonly class ProductOffer
{
    /**
     * @param list<string> $productIds
     * @param list<array{code: ?string, message: string, path: ?string}> $validationErrors
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $categoryId,
        public ?string $status,
        public ?string $format,
        public ?Money $price,
        public ?int $stockAvailable,
        public ?string $stockUnit,
        public ?string $externalId,
        public array $productIds = [],
        public array $validationErrors = [],
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $productIds = [];

        foreach (Read::rows($data, 'productSet') as $element) {
            $productId = Read::string($element, 'product.id');

            if ($productId !== null) {
                $productIds[] = $productId;
            }
        }

        $errors = array_map(static fn(array $error): array => [
            'code' => Read::string($error, 'code'),
            'message' => Read::string($error, 'userMessage') ?? Read::string($error, 'message') ?? 'Unknown error',
            'path' => Read::string($error, 'path'),
        ], Read::rows($data, 'validation.errors'));

        return new self(
            id: Read::string($data, 'id') ?? '',
            name: Read::string($data, 'name'),
            categoryId: Read::string($data, 'category.id'),
            status: Read::string($data, 'publication.status'),
            format: Read::string($data, 'sellingMode.format'),
            price: Money::fromArray(Read::arrayOrNull($data, 'sellingMode.price')),
            stockAvailable: Read::int($data, 'stock.available'),
            stockUnit: Read::string($data, 'stock.unit'),
            externalId: Read::string($data, 'external.id'),
            productIds: $productIds,
            validationErrors: $errors,
            createdAt: Read::date($data, 'createdAt'),
            updatedAt: Read::date($data, 'updatedAt'),
            raw: $data,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
