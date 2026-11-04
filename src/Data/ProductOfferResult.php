<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

/**
 * Result of creating or editing an offer through /sale/product-offers.
 *
 * Allegro answers 200/201 when the change was applied synchronously and
 * 202 when it is still being processed; in that case $operationUrl (the
 * Location header) points to the operation to poll and $retryAfter
 * suggests the polling interval in seconds. Pass the result to
 * Offers::waitForOperation() to wait for the final offer.
 */
final readonly class ProductOfferResult
{
    public function __construct(
        public int $httpStatus,
        public ProductOffer $offer,
        public ?string $operationUrl = null,
        public ?int $retryAfter = null,
    ) {}

    public function isPending(): bool
    {
        return $this->httpStatus === 202;
    }
}
