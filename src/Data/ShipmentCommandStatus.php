<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Support\Read;

/**
 * Status of an asynchronous Wysyłam z Allegro command (shipment creation, cancellation or pickup order).
 *
 * $status is IN_PROGRESS, SUCCESS or ERROR. $shipmentId is set once a
 * shipment was created; $pickupId and $carrierPickupId for pickup orders.
 */
final readonly class ShipmentCommandStatus
{
    public const string IN_PROGRESS = 'IN_PROGRESS';

    public const string SUCCESS = 'SUCCESS';

    public const string ERROR = 'ERROR';

    /**
     * @param list<array{code: ?string, message: string, details: ?string, path: ?string, userMessage: ?string}> $errors
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $commandId,
        public string $status,
        public ?string $shipmentId = null,
        public ?string $pickupId = null,
        public ?string $carrierPickupId = null,
        public array $errors = [],
        public ?int $retryAfter = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, ?int $retryAfter = null, ?string $commandId = null): self
    {
        return new self(
            commandId: Read::string($data, 'commandId') ?? $commandId ?? '',
            status: Read::string($data, 'status') ?? self::IN_PROGRESS,
            shipmentId: Read::string($data, 'shipmentId'),
            pickupId: Read::string($data, 'pickupId'),
            carrierPickupId: Read::string($data, 'carrierPickupId'),
            errors: ApiException::parseErrors(Read::array($data, 'errors')),
            retryAfter: $retryAfter,
            raw: $data,
        );
    }

    public function isFinished(): bool
    {
        return $this->status === self::SUCCESS || $this->status === self::ERROR;
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
