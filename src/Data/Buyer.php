<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Buyer of an order (CheckoutFormBuyerReference, or BuyerReference in order events).
 *
 * The e-mail is an Allegro relay address that forwards messages to the
 * buyer; it is not the buyer's private address.
 */
final readonly class Buyer
{
    public function __construct(
        public string $id,
        public ?string $email,
        public ?string $login,
        public bool $guest,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $companyName = null,
        public ?string $phoneNumber = null,
        public ?string $language = null,
        public ?Address $address = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            email: Read::string($data, 'email'),
            login: Read::string($data, 'login'),
            guest: Read::bool($data, 'guest') ?? false,
            firstName: Read::string($data, 'firstName'),
            lastName: Read::string($data, 'lastName'),
            companyName: Read::string($data, 'companyName'),
            phoneNumber: Read::string($data, 'phoneNumber'),
            language: Read::string($data, 'preferences.language'),
            address: Address::fromArray(Read::arrayOrNull($data, 'address')),
        );
    }
}
