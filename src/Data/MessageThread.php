<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Conversation in the Allegro message center (Thread).
 */
final readonly class MessageThread
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public bool $read,
        public ?CarbonImmutable $lastMessageAt,
        public ?string $interlocutorLogin,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            read: Read::bool($data, 'read') ?? false,
            lastMessageAt: Read::date($data, 'lastMessageDateTime'),
            interlocutorLogin: Read::string($data, 'interlocutor.login'),
            raw: $data,
        );
    }
}
