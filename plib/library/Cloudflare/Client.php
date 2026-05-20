<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

use Noiz\CloudflareDns\Cloudflare\Transport\CurlTransport;
use Noiz\CloudflareDns\Cloudflare\Transport\Transport;

/**
 * Thin Cloudflare API v4 client: authentication, JSON encode/decode,
 * error handling, transient-failure retries and list pagination.
 */
final class Client
{
    public const BASE_URL = 'https://api.cloudflare.com/client/v4';

    /** HTTP statuses worth retrying with backoff. */
    private const RETRYABLE = [429, 502, 503, 504];

    private string $token;
    private Transport $transport;
    private int $maxRetries;

    public function __construct(string $token, ?Transport $transport = null, int $maxRetries = 3)
    {
        $token = trim($token);
        if ($token === '') {
            throw new ApiException('A Cloudflare API token is required.');
        }

        $this->token = $token;
        $this->transport = $transport ?? new CurlTransport();
        $this->maxRetries = max(0, $maxRetries);
    }

    /**
     * Perform a request and return just the decoded `result` payload.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar>     $query
     *
     * @return mixed
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [])
    {
        return $this->raw($method, $path, $body, $query)['result'] ?? null;
    }

    /**
     * Perform a request and return the full decoded envelope
     * (`result`, `result_info`, `errors`, `messages`).
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar>     $query
     *
     * @return array<string,mixed>
     */
    public function raw(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = self::BASE_URL . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $response = $this->transport->send($method, $url, $headers, $encodedBody);

            if (in_array($response->status, self::RETRYABLE, true) && $attempt <= $this->maxRetries) {
                // Exponential backoff, capped at 30s.
                sleep((int) min(30, 2 ** $attempt));
                continue;
            }

            $decoded = json_decode($response->body, true);
            if (!is_array($decoded)) {
                throw new ApiException(
                    sprintf('Malformed Cloudflare response (HTTP %d).', $response->status),
                    $response->status
                );
            }

            if ($response->status >= 400 || ($decoded['success'] ?? false) !== true) {
                throw ApiException::fromResponse($response->status, $decoded);
            }

            return $decoded;
        }
    }

    /**
     * Fetch every page of a list endpoint and return the merged `result` rows.
     *
     * @param array<string,scalar> $query
     *
     * @return array<int,mixed>
     */
    public function requestAll(string $path, array $query = []): array
    {
        $page = 1;
        $perPage = 100;
        $rows = [];

        do {
            $envelope = $this->raw('GET', $path, null, $query + ['page' => $page, 'per_page' => $perPage]);

            $pageRows = $envelope['result'] ?? [];
            if (is_array($pageRows)) {
                foreach ($pageRows as $row) {
                    $rows[] = $row;
                }
            }

            $info = $envelope['result_info'] ?? [];
            $totalPages = is_array($info) ? (int) ($info['total_pages'] ?? 1) : 1;
            $page++;
        } while ($page <= $totalPages);

        return $rows;
    }

    /**
     * Verify that the API token is valid and active.
     *
     * Returns false (rather than throwing) when Cloudflare rejects the token.
     */
    public function verifyToken(): bool
    {
        try {
            $this->request('GET', 'user/tokens/verify');

            return true;
        } catch (ApiException $e) {
            return false;
        }
    }
}
