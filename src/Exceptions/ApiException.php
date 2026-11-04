<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Exceptions;

use Throwable;

/**
 * Raised when the Allegro REST API rejects a request or cannot be reached.
 *
 * errors() lists the entries of the standard error envelope
 * ({"errors": [{code, message, details, path, userMessage}]}); the OAuth2
 * envelope returned with HTTP 401 ({"error", "error_description"}) is
 * converted into a single entry. traceId() is the value of the Trace-Id
 * response header, which Allegro support asks for when reporting problems.
 */
final class ApiException extends AllegroException
{
    /**
     * @param list<array{code: ?string, message: string, details: ?string, path: ?string, userMessage: ?string}> $errors
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
        private readonly ?string $traceId = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload Decoded response body.
     */
    public static function fromResponse(array $payload, int $httpStatus, ?string $traceId = null, ?int $retryAfter = null): self
    {
        $errors = self::parseErrors(is_array($payload['errors'] ?? null) ? $payload['errors'] : []);

        if ($errors === [] && is_string($payload['error'] ?? null)) {
            $errors[] = [
                'code' => $payload['error'],
                'message' => is_string($payload['error_description'] ?? null) ? $payload['error_description'] : $payload['error'],
                'details' => null,
                'path' => null,
                'userMessage' => null,
            ];
        }

        $summary = implode('; ', array_map(
            static fn(array $error): string => ($error['path'] !== null ? $error['path'] . ': ' : '') . ($error['userMessage'] ?? $error['message']),
            $errors,
        ));

        return new self(
            sprintf('Allegro request failed (HTTP %d)%s%s', $httpStatus, $summary !== '' ? ': ' . $summary : '.', $traceId !== null ? ' [Trace-Id ' . $traceId . ']' : ''),
            $httpStatus,
            $errors,
            $traceId,
            $retryAfter,
        );
    }

    /**
     * @param list<array{code: ?string, message: string, details: ?string, path: ?string, userMessage: ?string}> $errors
     */
    public static function commandFailed(string $commandId, string $status, array $errors): self
    {
        $summary = implode('; ', array_map(static fn(array $error): string => $error['userMessage'] ?? $error['message'], $errors));

        return new self(sprintf('Allegro command %s finished with status "%s"%s', $commandId, $status, $summary !== '' ? ': ' . $summary : '.'), errors: $errors);
    }

    public static function timeout(string $operation): self
    {
        return new self(sprintf('Allegro operation %s is still running; check its status again later.', $operation));
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not connect to Allegro: ' . $previous->getMessage(), previous: $previous);
    }

    public static function unexpected(string $message, int $httpStatus, ?string $traceId = null): self
    {
        return new self($message, $httpStatus, traceId: $traceId);
    }

    /**
     * @param array<array-key, mixed> $errors
     * @return list<array{code: ?string, message: string, details: ?string, path: ?string, userMessage: ?string}>
     */
    public static function parseErrors(array $errors): array
    {
        $string = static fn(array $error, string $key): ?string => is_scalar($error[$key] ?? null) && (string) $error[$key] !== '' ? (string) $error[$key] : null;
        $parsed = [];

        foreach ($errors as $error) {
            if (is_array($error)) {
                $parsed[] = [
                    'code' => $string($error, 'code'),
                    'message' => $string($error, 'message') ?? 'Unknown error',
                    'details' => $string($error, 'details'),
                    'path' => $string($error, 'path'),
                    'userMessage' => $string($error, 'userMessage'),
                ];
            }
        }

        return $parsed;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return list<array{code: ?string, message: string, details: ?string, path: ?string, userMessage: ?string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Message of the first error intended for the end user, in the language requested with Accept-Language.
     */
    public function userMessage(): ?string
    {
        foreach ($this->errors as $error) {
            if ($error['userMessage'] !== null) {
                return $error['userMessage'];
            }
        }

        return null;
    }

    /**
     * Whether any error carries the given code, for example "NotFoundException".
     */
    public function hasErrorCode(string $code): bool
    {
        return in_array($code, array_column($this->errors, 'code'), true);
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    /**
     * Seconds to wait before retrying, when Allegro sent a Retry-After header.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    /**
     * HTTP 409: a stale checkoutForm.revision, a reused commandId or a concurrent offer modification.
     */
    public function isConflict(): bool
    {
        return $this->httpStatus === 409;
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }

    public function isValidationError(): bool
    {
        return $this->httpStatus === 400 || $this->httpStatus === 422;
    }
}
