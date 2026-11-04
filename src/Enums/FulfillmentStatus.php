<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Enums;

/**
 * Seller-side processing status of an order (CheckoutFormFulfillmentStatus).
 */
enum FulfillmentStatus: string
{
    case New = 'NEW';
    case Processing = 'PROCESSING';
    case ReadyForShipment = 'READY_FOR_SHIPMENT';
    case ReadyForPickup = 'READY_FOR_PICKUP';
    case Sent = 'SENT';
    case PickedUp = 'PICKED_UP';
    case Cancelled = 'CANCELLED';
    case Suspended = 'SUSPENDED';
    case Returned = 'RETURNED';
}
