<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Support\Read;

/**
 * Result of a batch offer command for a single offer (CommandTask).
 *
 * $status is NEW, SUCCESS or FAIL; $errors explain a failure.
 */
final readonly class CommandTask
{
    /**
     * @param list<array{code: ?string, message: string, details: ?string, path: ?string, userMessage: ?string}> $errors
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $offerId,
        public ?string $status,
        public ?string $message,
        public ?string $field,
        public array $errors = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            offerId: Read::string($data, 'offer.id'),
            status: Read::string($data, 'status'),
            message: Read::string($data, 'message'),
            field: Read::string($data, 'field'),
            errors: ApiException::parseErrors(Read::array($data, 'errors')),
            raw: $data,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'SUCCESS';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAIL';
    }
}
