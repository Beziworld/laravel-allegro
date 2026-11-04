<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\Shipment;
use Beziworld\Allegro\Data\ShipmentCommandStatus;
use Beziworld\Allegro\Exceptions\ApiException;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Wysyłam z Allegro (/shipment-management): creating and cancelling shipments, labels, protocols and courier pickups.
 *
 * Creation, cancellation and pickup orders are asynchronous commands: the
 * client generates a UUID commandId, sends the command and polls its
 * status until it is SUCCESS or ERROR.
 */
final readonly class ShipmentManagement
{
    public function __construct(private AllegroClient $client) {}

    /**
     * Proposed shipment settings for an order (delivery service, sender, receiver, packages, label format).
     *
     * This is the recommended source of the input for createShipment().
     *
     * @return array<array-key, mixed>
     */
    public function deliveryProposals(string $orderId): array
    {
        return $this->client->request('GET', '/shipment-management/delivery-proposals/' . rawurlencode($orderId));
    }

    /**
     * Delivery services available to the seller. Allegro marks this resource as deprecated; prefer deliveryProposals().
     *
     * @return array<array-key, mixed>
     */
    public function deliveryServices(): array
    {
        return $this->client->request('GET', '/shipment-management/delivery-services');
    }

    /**
     * Starts creating a shipment (POST /shipment-management/shipments/create-commands).
     *
     * @param array<string, mixed> $input ShipmentCreateRequestDto: sender, receiver, packages (type, length, width,
     *     height, weight with units), and optionally deliveryMethodId, credentialsId, referenceNumber, insurance,
     *     cashOnDelivery, labelFormat (PDF or ZPL), additionalServices.
     * @param string|null $commandId UUID; generated when null.
     */
    public function createShipment(array $input, ?string $commandId = null): ShipmentCommandStatus
    {
        $commandId ??= (string) Str::uuid();
        $response = $this->client->send('POST', '/shipment-management/shipments/create-commands', [], ['commandId' => $commandId, 'input' => $input]);

        return self::status($response, $commandId);
    }

    public function creationStatus(string $commandId): ShipmentCommandStatus
    {
        return self::status($this->client->send('GET', '/shipment-management/shipments/create-commands/' . rawurlencode($commandId)), $commandId);
    }

    /**
     * Polls a create command until it finishes and returns the created shipment.
     *
     * @throws ApiException When the command ends with ERROR or does not finish in time.
     */
    public function waitForShipment(ShipmentCommandStatus|string $command): Shipment
    {
        $status = $this->waitFor($command, $this->creationStatus(...));

        return $this->shipment($status->shipmentId ?? throw ApiException::commandFailed($status->commandId, $status->status, $status->errors));
    }

    public function shipment(string $shipmentId): Shipment
    {
        return Shipment::fromArray($this->client->request('GET', '/shipment-management/shipments/' . rawurlencode($shipmentId)));
    }

    /**
     * Starts cancelling a shipment (POST /shipment-management/shipments/cancel-commands).
     */
    public function cancelShipment(string $shipmentId, ?string $commandId = null): ShipmentCommandStatus
    {
        $commandId ??= (string) Str::uuid();
        $response = $this->client->send('POST', '/shipment-management/shipments/cancel-commands', [], ['commandId' => $commandId, 'input' => ['shipmentId' => $shipmentId]]);

        return self::status($response, $commandId);
    }

    public function cancellationStatus(string $commandId): ShipmentCommandStatus
    {
        return self::status($this->client->send('GET', '/shipment-management/shipments/cancel-commands/' . rawurlencode($commandId)), $commandId);
    }

    /**
     * Polls a cancel command until it finishes.
     *
     * @throws ApiException When the command ends with ERROR or does not finish in time.
     */
    public function waitForCancellation(ShipmentCommandStatus|string $command): ShipmentCommandStatus
    {
        return $this->waitFor($command, $this->cancellationStatus(...));
    }

    /**
     * Labels of the shipments as binary content (PDF or ZPL, as chosen when the shipment was created).
     *
     * @param list<string>|string $shipmentIds
     * @param 'A4'|'A6'|null $pageSize Applies to PDF labels only.
     * @param bool|null $cutLine Cut lines, for PDF labels on A4 only.
     * @param array{placement: string, fields: list<string>}|null $summaryReport Packing report, e.g. ['placement' => 'LAST', 'fields' => ['WAYBILL', 'ITEMS']].
     * @return string|null The file, or null when no label is available (HTTP 204).
     */
    public function label(array|string $shipmentIds, ?string $pageSize = 'A6', ?bool $cutLine = null, ?array $summaryReport = null): ?string
    {
        return self::binary($this->client->send('POST', '/shipment-management/label', [], array_filter([
            'shipmentIds' => (array) $shipmentIds,
            'pageSize' => $pageSize,
            'cutLine' => $cutLine,
            'summaryReport' => $summaryReport,
        ], static fn(mixed $value): bool => $value !== null), accept: 'application/octet-stream'));
    }

    /**
     * Dispatch protocol of the shipments as a PDF; null when the carrier does not provide one (HTTP 204).
     *
     * @param list<string>|string $shipmentIds
     */
    public function protocol(array|string $shipmentIds): ?string
    {
        return self::binary($this->client->send('POST', '/shipment-management/protocol', [], ['shipmentIds' => (array) $shipmentIds], accept: 'application/octet-stream'));
    }

    /**
     * Courier pickup time slots for the shipments.
     *
     * @param list<string>|string $shipmentIds
     * @param array<string, mixed> $address Pickup address: name, company, street, postalCode, city, countryCode, email, phone.
     * @return array<array-key, mixed>
     */
    public function pickupProposals(array|string $shipmentIds, array $address, DateTimeInterface|string|null $readyDate = null): array
    {
        return $this->client->request('POST', '/shipment-management/pickup-proposals', [], array_filter([
            'shipmentIds' => (array) $shipmentIds,
            'address' => $address,
            'readyDate' => $readyDate instanceof DateTimeInterface ? $readyDate->format('Y-m-d') : $readyDate,
        ], static fn(mixed $value): bool => $value !== null));
    }

    /**
     * Orders a courier pickup (POST /shipment-management/pickups/create-commands).
     *
     * @param list<string>|string $shipmentIds
     * @param array<string, mixed> $address
     * @param array{date: string, minTime?: string, maxTime?: string}|null $pickupTime Slot from pickupProposals().
     */
    public function createPickup(array|string $shipmentIds, array $address, ?array $pickupTime = null, ?string $commandId = null): ShipmentCommandStatus
    {
        $commandId ??= (string) Str::uuid();
        $response = $this->client->send('POST', '/shipment-management/pickups/create-commands', [], [
            'commandId' => $commandId,
            'input' => array_filter([
                'shipmentIds' => (array) $shipmentIds,
                'pickupTime' => $pickupTime,
                'address' => $address,
            ], static fn(mixed $value): bool => $value !== null),
        ]);

        return self::status($response, $commandId);
    }

    public function pickupStatus(string $commandId): ShipmentCommandStatus
    {
        return self::status($this->client->send('GET', '/shipment-management/pickups/create-commands/' . rawurlencode($commandId)), $commandId);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function pickup(string $pickupId): array
    {
        return $this->client->request('GET', '/shipment-management/pickups/' . rawurlencode($pickupId));
    }

    /**
     * @param \Closure(string): ShipmentCommandStatus $check
     */
    private function waitFor(ShipmentCommandStatus|string $command, \Closure $check): ShipmentCommandStatus
    {
        $commandId = $command instanceof ShipmentCommandStatus ? $command->commandId : $command;
        $retryAfter = $command instanceof ShipmentCommandStatus ? $command->retryAfter : null;

        for ($attempt = 1; $attempt <= $this->client->config()->pollAttempts; $attempt++) {
            $this->client->pause($retryAfter);
            $status = $check($commandId);

            if ($status->isFinished()) {
                return $status->isSuccessful() ? $status : throw ApiException::commandFailed($commandId, $status->status, $status->errors);
            }

            $retryAfter = $status->retryAfter;
        }

        throw ApiException::timeout($commandId);
    }

    private static function status(Response $response, string $commandId): ShipmentCommandStatus
    {
        $data = $response->json();
        $retryAfter = $response->header('Retry-After');

        return ShipmentCommandStatus::fromArray(is_array($data) ? $data : [], is_numeric($retryAfter) ? (int) $retryAfter : null, $commandId);
    }

    private static function binary(Response $response): ?string
    {
        return $response->status() === 204 || $response->body() === '' ? null : $response->body();
    }
}
