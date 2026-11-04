<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Summary of a batch offer command (GeneralReport), for example a price or quantity change.
 *
 * The command is finished when $completedAt is set; per-offer results are
 * read with the matching "...Tasks()" method.
 */
final readonly class CommandReport
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $completedAt,
        public int $total,
        public int $success,
        public int $failed,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Read::string($data, 'id') ?? '',
            createdAt: Read::date($data, 'createdAt'),
            completedAt: Read::date($data, 'completedAt'),
            total: Read::int($data, 'taskCount.total') ?? 0,
            success: Read::int($data, 'taskCount.success') ?? 0,
            failed: Read::int($data, 'taskCount.failed') ?? 0,
            raw: $data,
        );
    }

    public function isCompleted(): bool
    {
        return $this->completedAt !== null || ($this->total > 0 && $this->success + $this->failed >= $this->total);
    }
}
