<?php

declare(strict_types=1);

namespace Superroute\SLocker;

/** Verifies the webhooks Superroute sends, and signs test requests the same way. */
final class Webhooks
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Checks a webhook request and answers its event, or throws WebhookVerificationException.
     *
     * @param string $body the raw request body exactly as received (file_get_contents('php://input'))
     * @param array<string, string|string[]> $headers
     * @param string|string[] $secrets the signing secret, or several during a rotation window
     */
    public static function verify(string $body, array $headers, string|array $secrets, int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS, ?int $now = null): WebhookEvent
    {
        $h = [];
        foreach ($headers as $name => $value) {
            $h[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        $keys = array_values(array_filter(is_array($secrets) ? $secrets : [$secrets], static fn ($s) => is_string($s) && $s !== ''));
        if (($h['x-webhook-signature'] ?? '') !== '') {
            foreach (['x-webhook-timestamp', 'x-webhook-delivery-id', 'x-webhook-content-digest', 'x-webhook-signature'] as $name) {
                if (($h[$name] ?? '') === '') {
                    throw new WebhookVerificationException('MISSING_HEADER');
                }
            }
            $ts = $h['x-webhook-timestamp'];
            self::timestamp($ts, $toleranceSeconds, $now);
            if (!hash_equals('sha256=' . base64_encode(hash('sha256', $body, true)), $h['x-webhook-content-digest'])) {
                throw new WebhookVerificationException('DIGEST_MISMATCH');
            }
            $message = $ts . "\n" . $h['x-webhook-delivery-id'] . "\n" . $body;
            $matched = false;
            foreach ($keys as $secret) {
                $matched = hash_equals('v1=' . base64_encode(hash_hmac('sha256', $message, $secret, true)), $h['x-webhook-signature']) || $matched;
            }
            if (!$matched) {
                throw new WebhookVerificationException('SIGNATURE_MISMATCH');
            }
            $native = true;
        } elseif (($h['webhook-signature'] ?? '') !== '') {
            if (($h['webhook-id'] ?? '') === '' || ($h['webhook-timestamp'] ?? '') === '') {
                throw new WebhookVerificationException('MISSING_HEADER');
            }
            $ts = $h['webhook-timestamp'];
            self::timestamp($ts, $toleranceSeconds, $now);
            $message = $h['webhook-id'] . '.' . $ts . '.' . $body;
            $matched = false;
            foreach ($keys as $secret) {
                $expected = 'v1,' . base64_encode(hash_hmac('sha256', $message, $secret, true));
                foreach (array_filter(explode(' ', $h['webhook-signature']), static fn ($p) => $p !== '') as $received) {
                    $matched = hash_equals($expected, $received) || $matched;
                }
            }
            if (!$matched) {
                throw new WebhookVerificationException('SIGNATURE_MISMATCH');
            }
            $native = false;
        } else {
            throw new WebhookVerificationException('MISSING_HEADER');
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookVerificationException('INVALID_PAYLOAD');
        }
        if (!is_array($payload) || array_is_list($payload) && $payload !== []) {
            throw new WebhookVerificationException('INVALID_PAYLOAD');
        }
        if (array_key_exists('specversion', $payload) && is_array($payload['data'] ?? null) && !array_is_list($payload['data'])) {
            $payload = $payload['data'];
        }
        if (!is_string($payload['event_type'] ?? null)) {
            throw new WebhookVerificationException('INVALID_PAYLOAD');
        }
        $testHeader = ($h['x-webhook-test'] ?? null) === '1';
        $livemode = is_bool($payload['livemode'] ?? null) ? $payload['livemode'] : !$testHeader;
        $eventId = is_string($payload['event_id'] ?? null) ? $payload['event_id'] : ($h['x-webhook-id'] ?? $h['webhook-id'] ?? null);

        return new WebhookEvent(
            $eventId,
            $payload['event_type'],
            $livemode,
            $testHeader || !$livemode,
            is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : null,
            is_array($payload['data'] ?? null) ? $payload['data'] : [],
            $payload,
            $native ? ($h['x-webhook-delivery-id'] ?? null) : null,
            $native ? ($h['x-webhook-key-id'] ?? null) : null,
            $h,
        );
    }

    /**
     * The headers Superroute would send with $body: for testing your own webhook receiver.
     *
     * @return array<string, string>
     */
    public static function sign(string $secret, string $body, ?int $timestamp = null, ?string $deliveryId = null, ?string $keyId = null, ?string $eventId = null, bool $test = false): array
    {
        $ts = (string) ($timestamp ?? time());
        $did = $deliveryId ?? bin2hex(random_bytes(16));
        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Delivery-Id' => $did,
            'X-Webhook-Timestamp' => $ts,
            'X-Webhook-Content-Digest' => 'sha256=' . base64_encode(hash('sha256', $body, true)),
            'X-Webhook-Signature' => 'v1=' . base64_encode(hash_hmac('sha256', $ts . "\n" . $did . "\n" . $body, $secret, true)),
        ];
        if ($eventId !== null) {
            $headers['X-Webhook-Id'] = $eventId;
        }
        if ($keyId !== null) {
            $headers['X-Webhook-Key-Id'] = $keyId;
        }
        if ($test) {
            $headers['X-Webhook-Test'] = '1';
        }

        return $headers;
    }

    private static function timestamp(string $value, int $tolerance, ?int $now): void
    {
        if (preg_match('/\A[0-9]{1,12}\z/', $value) !== 1) {
            throw new WebhookVerificationException('INVALID_TIMESTAMP');
        }
        if (abs(($now ?? time()) - (int) $value) > $tolerance) {
            throw new WebhookVerificationException('TIMESTAMP_OUT_OF_TOLERANCE');
        }
    }
}
