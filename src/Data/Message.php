<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Message in the Allegro message center.
 *
 * $authorIsInterlocutor is true for messages written by the buyer and
 * false for the seller's own messages. $attachments lists {fileName,
 * mimeType, url, status}; download them with Messaging::attachment().
 */
final readonly class Message
{
    /**
     * @param list<array{fileName: ?string, mimeType: ?string, url: ?string, status: ?string}> $attachments
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $threadId,
        public ?string $status,
        public ?string $type,
        public ?CarbonImmutable $createdAt,
        public ?string $authorLogin,
        public bool $authorIsInterlocutor,
        public string $text,
        public ?string $subject = null,
        public ?string $offerId = null,
        public ?string $orderId = null,
        public array $attachments = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            threadId: Read::string($data, 'thread.id'),
            status: Read::string($data, 'status'),
            type: Read::string($data, 'type'),
            createdAt: Read::date($data, 'createdAt'),
            authorLogin: Read::string($data, 'author.login'),
            authorIsInterlocutor: Read::bool($data, 'author.isInterlocutor') ?? false,
            text: Read::string($data, 'text') ?? '',
            subject: Read::string($data, 'subject'),
            offerId: Read::string($data, 'relatesTo.offer.id'),
            orderId: Read::string($data, 'relatesTo.order.id'),
            attachments: array_map(static fn(array $attachment): array => [
                'fileName' => Read::string($attachment, 'fileName'),
                'mimeType' => Read::string($attachment, 'mimeType'),
                'url' => Read::string($attachment, 'url'),
                'status' => Read::string($attachment, 'status'),
            ], Read::rows($data, 'attachments')),
            raw: $data,
        );
    }
}
