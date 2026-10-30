<?php

declare(strict_types=1);

namespace Superroute\SLocker;

/** A verified webhook: partner_locker.* or carrier_package.distribution.* */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly ?string $eventId,
        public readonly string $eventType,
        public readonly bool $livemode,
        public readonly bool $test,
        public readonly ?string $occurredAt,
        public readonly array $data,
        public readonly array $payload,
        public readonly ?string $deliveryId,
        public readonly ?string $keyId,
        public readonly array $headers,
    ) {
    }
}
