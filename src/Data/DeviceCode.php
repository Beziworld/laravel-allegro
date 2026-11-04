<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Data;

use Beziworld\Allegro\Support\Read;
use Carbon\CarbonImmutable;

/**
 * Response of POST /auth/oauth/device: the codes of a device-flow authorization.
 *
 * Show userCode and verificationUri (or the clickable
 * verificationUriComplete) to the seller, then poll the token endpoint
 * with deviceCode no more often than every "interval" seconds.
 */
final readonly class DeviceCode
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public ?string $verificationUriComplete,
        public int $interval,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            deviceCode: Read::string($data, 'device_code') ?? '',
            userCode: Read::string($data, 'user_code') ?? '',
            verificationUri: Read::string($data, 'verification_uri') ?? '',
            verificationUriComplete: Read::string($data, 'verification_uri_complete'),
            interval: max(1, Read::int($data, 'interval') ?? 5),
            expiresAt: CarbonImmutable::now()->addSeconds(Read::int($data, 'expires_in') ?? 3600),
        );
    }

    /**
     * User code split into groups of three characters ("cbt 3zd u4g"), as Allegro recommends for display.
     */
    public function formattedUserCode(): string
    {
        return implode(' ', str_split($this->userCode, 3));
    }

    public function isExpired(): bool
    {
        return ! $this->expiresAt->isFuture();
    }
}
