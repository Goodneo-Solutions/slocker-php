<?php

declare(strict_types=1);

namespace Superroute\SLocker;

use Superroute\SLocker\Generated\OperationMethods;
use Superroute\SLocker\Generated\Operations;

/** A client of the Superroute partner locker delivery API. */
final class Client
{
    use OperationMethods;

    public const VERSION = '0.2.1';
    public const DEFAULT_BASE_URL = 'https://api.superroute.ca';
    private const RETRY_STATUSES = [408, 425, 429, 500, 502, 503, 504];
    private const MAX_RETRY_AFTER = 60;

    private readonly string $baseUrl;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleep;

    /**
     * @param ?string $token an access token (POST /api/v1/user/login), or sign in with login()
     * @param ?int $providerId the third-party delivery provider to act as (X-Third-Party-Provider-Id)
     * @param int $maxRetries how often a repeatable call (reads, deletes, creates with an idempotency key) is repeated
     *                        after a timeout, 408, 425, 429 or 5xx answer
     * @param ?callable(float): void $sleep
     */
    public function __construct(
        public ?string $token = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public ?int $providerId = null,
        public readonly int $maxRetries = 2,
        ?Transport $transport = null,
        ?callable $sleep = null,
        private readonly string $userAgent = 'slocker-php/' . self::VERSION,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /** Signs in (POST /api/v1/user/login) and keeps the access token for the following calls. */
    public function login(string $email, string $password, ?int $businessId = null): mixed
    {
        $body = ['email' => $email, 'password' => $password];
        if ($businessId !== null) {
            $body['business_id'] = $businessId;
        }

        return $this->takeToken($this->send('POST', '/v1/user/login', $body, auth: false));
    }

    /** A new token for the current one (POST /api/v1/user/refresh-token). */
    public function refreshToken(): mixed
    {
        return $this->takeToken($this->send('POST', '/v1/user/refresh-token'));
    }

    /** A new token, the current one valid for the grace period (POST /api/v1/user/rotate-token). */
    public function rotateToken(?int $gracePeriodMinutes = null): mixed
    {
        $body = $gracePeriodMinutes === null ? null : ['grace_period_minutes' => $gracePeriodMinutes];

        return $this->takeToken($this->send('POST', '/v1/user/rotate-token', $body));
    }

    /** Revokes the token (GET /api/v1/user/logout) and forgets it. */
    public function logout(): mixed
    {
        $result = $this->send('GET', '/v1/user/logout');
        $this->token = null;

        return $result;
    }

    private function takeToken(mixed $result): mixed
    {
        if (is_array($result) && is_string($result['access_token'] ?? null)) {
            $this->token = $result['access_token'];
        }

        return $result;
    }

    /**
     * Runs one operation by name with its path and query parameters by their spec names, body, idempotencyKey and
     * providerId. Answers the response body as parsed JSON (arrays).
     *
     * @param array<string, mixed> $params
     */
    public function call(string $operation, array $params = []): mixed
    {
        $op = Operations::TABLE[$operation] ?? null;
        if ($op === null) {
            throw new SLockerException(0, 'INVALID_ARGUMENT', "Unknown operation {$operation}");
        }
        $path = $op['path'];
        foreach ($op['pathParams'] as $name) {
            $value = $params[$name] ?? null;
            if ($value === null || $value === '') {
                throw new SLockerException(0, 'INVALID_ARGUMENT', "{$operation} needs {$name}");
            }
            $path = str_replace('{' . $name . '}', rawurlencode(self::text($value)), $path);
        }
        $query = [];
        foreach ($op['query'] as $name) {
            if (isset($params[$name])) {
                $query[] = $name . '=' . rawurlencode(self::text($params[$name]));
            }
        }
        if ($query !== []) {
            $path .= '?' . implode('&', $query);
        }
        $key = null;
        if ($op['idempotent']) {
            $key = isset($params['idempotencyKey']) && $params['idempotencyKey'] !== '' ? (string) $params['idempotencyKey'] : self::uuid();
        }

        return $this->send(
            $op['method'],
            $path,
            $op['body'] ? ($params['body'] ?? null) : null,
            retry: $op['retry'],
            idempotencyKey: $key,
            providerId: isset($params['providerId']) ? (int) $params['providerId'] : $this->providerId,
        );
    }

    private function send(string $method, string $path, ?array $body = null, bool $retry = false, bool $auth = true,
                          ?string $idempotencyKey = null, ?int $providerId = null): mixed
    {
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($auth && $this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        if ($providerId !== null) {
            $headers['X-Third-Party-Provider-Id'] = (string) $providerId;
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $data = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $data = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $url = $this->baseUrl . '/api' . $path;
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $data);
            } catch (\Throwable $e) {
                if ($retry && $attempt < $this->maxRetries) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                throw new SLockerException(0, 'NETWORK_ERROR', $e->getMessage() !== '' ? $e->getMessage() : get_class($e), previous: $e);
            }
            $status = $response['status'];
            $parsed = self::parse($response['body']);
            if ($status >= 200 && $status < 300) {
                return $parsed;
            }
            if ($retry && in_array($status, self::RETRY_STATUSES, true) && $attempt < $this->maxRetries) {
                $ra = self::retryAfter($response['headers'], null);
                ($this->sleep)($ra !== null ? (float) min($ra, self::MAX_RETRY_AFTER) : self::backoff($attempt));
                continue;
            }
            throw self::errorFrom($status, $response['headers'], $parsed);
        }
    }

    /** @param array<string, string> $headers */
    public static function errorFrom(int $status, array $headers, mixed $body): SLockerException
    {
        $data = is_array($body) ? $body : [];
        $code = is_string($data['code'] ?? null) && $data['code'] !== '' ? $data['code'] : ($status === 401 ? 'UNAUTHENTICATED' : 'HTTP_' . $status);
        $message = is_string($data['message'] ?? null) ? $data['message'] : 'HTTP ' . $status;

        return new SLockerException($status, $code, $message, $data['errors'] ?? null, self::retryAfter($headers, $body), $body);
    }

    /** @param array<string, string> $headers */
    private static function retryAfter(array $headers, mixed $body): ?int
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after' && preg_match('/^\s*(\d+)\s*$/', (string) $value, $m) === 1) {
                return (int) $m[1];
            }
        }
        $seconds = is_array($body) && is_array($body['errors'] ?? null) ? ($body['errors']['retry_after_seconds'] ?? null) : null;

        return is_int($seconds) ? $seconds : null;
    }

    private static function parse(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    private static function backoff(int $attempt): float
    {
        return min(8.0, 0.5 * (2 ** $attempt)) * (0.5 + mt_rand() / mt_getrandmax() / 2);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
