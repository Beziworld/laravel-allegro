<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Enums;

/**
 * Buyer-side status of a checkout form (CheckoutFormStatus).
 *
 * Address and invoice data are final only in READY_FOR_PROCESSING.
 */
enum OrderStatus: string
{
    case Bought = 'BOUGHT';
    case FilledIn = 'FILLED_IN';
    case ReadyForProcessing = 'READY_FOR_PROCESSING';
    case Cancelled = 'CANCELLED';
}
