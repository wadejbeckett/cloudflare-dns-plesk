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
        if (preg_match('/[\r\n\0]/', $token)) {
            throw new ApiException('Cloudflare API token contains invalid characters.');
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
    public function request(string $method, string $path, ?array $body = null, array $query = []): mixed
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
        // The path is interpolated straight into the URL before the query
        // string. Reject anything that could smuggle a query/fragment or a
        // control byte (whitespace, `?`, `#`, C0 controls, DEL), and any
        // `.`/`..` dot-segment — a `..` would retarget the request one path
        // level up (e.g. turn a record DELETE into a zone DELETE). Slashes
        // are legitimate separators; no Cloudflare v4 path has dot-segments.
        if (preg_match('/[\s?#\x00-\x1F\x7F]/', $path)
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)
        ) {
            throw new ApiException('Invalid Cloudflare API path.');
        }

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

        // Wall-clock ceiling for this logical request, checked before each
        // backoff sleep (projected sleep counted against the budget). With
        // the default maxRetries=3 and the transport's 30s timeout the retry
        // count is the binding limit and this never fires — it is belt-and-
        // braces so a future caller raising maxRetries or the transport
        // timeout cannot stall a synchronous poll unboundedly.
        $deadline = time() + 120;

        $attempt = 0;
        while (true) {
            $attempt++;
            // Exponential backoff, capped at 30s.
            $backoff = (int) min(30, 2 ** $attempt);

            // Transport-layer failures (DNS lookup, TLS handshake, connection
            // reset, timeout) come up as ApiException with httpStatus 0. They
            // are the exact case retry is meant for — bubble them only after
            // the retry budget is exhausted.
            try {
                $response = $this->transport->send($method, $url, $headers, $encodedBody);
            } catch (ApiException $e) {
                if ($this->canRetry($attempt, $backoff, $deadline)) {
                    sleep($backoff);
                    continue;
                }
                throw $e;
            }

            if (in_array($response->status, self::RETRYABLE, true)
                && $this->canRetry($attempt, $backoff, $deadline)
            ) {
                sleep($backoff);
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
     * The retry gate, shared by the transport-failure and retryable-status
     * branches. Evaluated AFTER each attempt — the attempt itself consumes
     * wall clock, so the deadline is re-read here, with the projected sleep
     * counted against the budget.
     */
    private function canRetry(int $attempt, int $backoff, int $deadline): bool
    {
        return $attempt <= $this->maxRetries && time() + $backoff < $deadline;
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
