<?php

declare(strict_types=1);

namespace Superroute\SLocker;

/**
 * A webhook that must be refused. reason is one of MISSING_HEADER, INVALID_TIMESTAMP, TIMESTAMP_OUT_OF_TOLERANCE,
 * DIGEST_MISMATCH, SIGNATURE_MISMATCH or INVALID_PAYLOAD.
 */
final class WebhookVerificationException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
