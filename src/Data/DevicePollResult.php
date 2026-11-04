<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

/**
 * Outcome of one device-flow token poll.
 *
 * "granted" carries the stored token, "pending" means the seller has not
 * confirmed yet and "slow_down" means the polling interval must grow.
 */
final readonly class DevicePollResult
{
    public const string GRANTED = 'granted';

    public const string PENDING = 'pending';

    public const string SLOW_DOWN = 'slow_down';

    public function __construct(
        public string $status,
        public ?AccessToken $token = null,
    ) {}

    public function isGranted(): bool
    {
        return $this->status === self::GRANTED && $this->token !== null;
    }
}
