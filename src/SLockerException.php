<?php

declare(strict_types=1);

namespace Superroute\SLocker;

/**
 * A call that failed. Act on the status and code, never on the message: status is the HTTP status (0 when no answer
 * came back or the call never left), code the server's stable code (LOCKER_DELIVERY_IN_USE, IDEMPOTENCY_CONFLICT, …),
 * UNAUTHENTICATED, HTTP_<status> when the answer carried none, NETWORK_ERROR or INVALID_ARGUMENT.
 */
final class SLockerException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $serverMessage,
        public readonly mixed $errors = null,
        public readonly ?int $retryAfter = null,
        public readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("{$errorCode} ({$status}): {$serverMessage}", 0, $previous);
    }
}
