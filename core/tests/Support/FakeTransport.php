<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests\Support;

use Noiz\CloudflareDns\Cloudflare\Transport\HttpResponse;
use Noiz\CloudflareDns\Cloudflare\Transport\Transport;

/**
 * A {@see Transport} that records every request and returns queued canned
 * responses — lets the API client be tested without a network or a real
 * Cloudflare account.
 */
final class FakeTransport implements Transport
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    /** @var HttpResponse[] */
    private array $queue = [];

    /**
     * Queue the next response as a Cloudflare-style JSON envelope.
     *
     * @param array<string,mixed> $envelope
     */
    public function queue(int $status, array $envelope): void
    {
        $this->queue[] = new HttpResponse($status, (string) json_encode($envelope));
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        if ($this->queue === []) {
            // Default: a successful, empty result envelope.
            return new HttpResponse(200, (string) json_encode(['success' => true, 'result' => []]));
        }

        return array_shift($this->queue);
    }

    /**
     * @return array{method:string,url:string,headers:array<string,string>,body:?string}
     */
    public function lastRequest(): array
    {
        $last = end($this->requests);

        return $last !== false ? $last : ['method' => '', 'url' => '', 'headers' => [], 'body' => null];
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
