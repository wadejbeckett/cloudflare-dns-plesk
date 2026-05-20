<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare\Transport;

/**
 * A bare HTTP response: status code and raw body.
 */
final class HttpResponse
{
    public int $status;
    public string $body;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
    }
}
