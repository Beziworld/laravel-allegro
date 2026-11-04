<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * Monetary amount as Allegro sends it: a decimal string and an ISO 4217 currency.
 *
 * The amount stays a string ("220.85") so that no precision is lost; use
 * a decimal library or bcmath for arithmetic.
 */
final readonly class Money
{
    public function __construct(
        public string $amount,
        public string $currency = 'PLN',
    ) {}

    /**
     * @param array<array-key, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $amount = Read::string($data, 'amount');

        return $amount === null ? null : new self($amount, Read::string($data, 'currency') ?? 'PLN');
    }

    /**
     * Builds an amount from a number or numeric string, formatted with two decimal places.
     */
    public static function of(int|float|string $amount, string $currency = 'PLN'): self
    {
        return new self(is_string($amount) ? $amount : number_format((float) $amount, 2, '.', ''), $currency);
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }
}
