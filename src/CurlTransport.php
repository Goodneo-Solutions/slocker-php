<?php

declare(strict_types=1);

namespace Superroute\SLocker;

/** The default transport: the curl extension. */
final class CurlTransport implements Transport
{
    public function __construct(private readonly float $timeoutSeconds = 30.0)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($handle);
        if ($result === false) {
            $message = curl_error($handle);
            throw new \RuntimeException($message !== '' ? $message : 'request failed');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result];
    }
}
