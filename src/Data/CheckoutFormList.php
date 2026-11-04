<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * One page of GET /order/checkout-forms.
 */
final readonly class CheckoutFormList
{
    /**
     * @param list<CheckoutForm> $checkoutForms
     */
    public function __construct(
        public array $checkoutForms,
        public int $count,
        public int $totalCount,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $forms = array_map(CheckoutForm::fromArray(...), Read::rows($data, 'checkoutForms'));

        return new self($forms, Read::int($data, 'count') ?? count($forms), Read::int($data, 'totalCount') ?? count($forms));
    }
}
