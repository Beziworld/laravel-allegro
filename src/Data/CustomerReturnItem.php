<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Returned item of a customer return.
 */
final readonly class CustomerReturnItem
{
    /**
     * @param list<string> $serialNumbers
     */
    public function __construct(
        public ?string $offerId,
        public ?string $name,
        public int $quantity,
        public ?Money $price,
        public ?string $reasonType,
        public ?string $reasonComment,
        public array $serialNumbers = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            offerId: Read::string($data, 'offerId'),
            name: Read::string($data, 'name'),
            quantity: Read::int($data, 'quantity') ?? 0,
            price: Money::fromArray(Read::arrayOrNull($data, 'price')),
            reasonType: Read::string($data, 'reason.type'),
            reasonComment: Read::string($data, 'reason.userComment'),
            serialNumbers: Read::strings($data, 'serialNumbers'),
        );
    }
}
