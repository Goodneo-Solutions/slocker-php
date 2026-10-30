<?php

declare(strict_types=1);

namespace Superroute\SLocker;

/** Sends one HTTP request. Throws on a request that got no answer. */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
