<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;

/**
 * One page of GET /sale/offers.
 */
final readonly class OfferList
{
    /**
     * @param list<OfferSummary> $offers
     */
    public function __construct(
        public array $offers,
        public int $count,
        public int $totalCount,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $offers = array_map(OfferSummary::fromArray(...), Read::rows($data, 'offers'));

        return new self($offers, Read::int($data, 'count') ?? count($offers), Read::int($data, 'totalCount') ?? count($offers));
    }
}
