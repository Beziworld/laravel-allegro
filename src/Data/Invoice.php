<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Invoice request of an order (CheckoutFormInvoiceInfo).
 *
 * $taxId is the company tax identifier (for Polish companies the NIP);
 * $companyIds lists every identifier as {type, value}, where type is for
 * example PL_NIP, VAT_EU or CZ_ICO.
 */
final readonly class Invoice
{
    /**
     * @param list<array{type: string, value: string}> $companyIds
     */
    public function __construct(
        public bool $required,
        public ?Address $address = null,
        public ?string $companyName = null,
        public ?string $taxId = null,
        public array $companyIds = [],
        public ?string $vatPayerStatus = null,
        public ?string $dueDate = null,
    ) {}

    /**
     * @param array<array-key, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || $data === []) {
            return null;
        }

        $ids = [];

        foreach (Read::rows($data, 'address.company.ids') as $id) {
            $type = Read::string($id, 'type');
            $value = Read::string($id, 'value');

            if ($type !== null && $value !== null) {
                $ids[] = ['type' => $type, 'value' => $value];
            }
        }

        $address = Read::arrayOrNull($data, 'address');

        if ($address !== null) {
            $address['firstName'] = Read::string($address, 'naturalPerson.firstName');
            $address['lastName'] = Read::string($address, 'naturalPerson.lastName');
            $address['companyName'] = Read::string($address, 'company.name');
        }

        return new self(
            required: Read::bool($data, 'required') ?? false,
            address: Address::fromArray($address),
            companyName: Read::string($data, 'address.company.name'),
            taxId: Read::string($data, 'address.company.taxId'),
            companyIds: $ids,
            vatPayerStatus: Read::string($data, 'address.company.vatPayerStatus'),
            dueDate: Read::string($data, 'dueDate'),
        );
    }
}
