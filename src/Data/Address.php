<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Postal address of a buyer, a delivery, an invoice or a pickup point.
 *
 * Allegro names the postal code "zipCode" in delivery and invoice
 * addresses and "postCode" in the buyer address; both are mapped to
 * $zipCode.
 */
final readonly class Address
{
    public function __construct(
        public ?string $street,
        public ?string $city,
        public ?string $zipCode,
        public ?string $countryCode,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $companyName = null,
        public ?string $phoneNumber = null,
    ) {}

    /**
     * @param array<array-key, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        return new self(
            street: Read::string($data, 'street'),
            city: Read::string($data, 'city'),
            zipCode: Read::string($data, 'zipCode') ?? Read::string($data, 'postCode'),
            countryCode: Read::string($data, 'countryCode'),
            firstName: Read::string($data, 'firstName'),
            lastName: Read::string($data, 'lastName'),
            companyName: Read::string($data, 'companyName'),
            phoneNumber: Read::string($data, 'phoneNumber'),
        );
    }

    /**
     * First and last name joined with a space, or null when neither is present.
     */
    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name !== '' ? $name : null;
    }
}
