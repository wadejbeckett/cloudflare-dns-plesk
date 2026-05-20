<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare\Transport;

/**
 * Minimal HTTP transport abstraction.
 *
 * Keeping this behind an interface means the API client can be unit-tested
 * with a fake transport — no network, no real Cloudflare account.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
