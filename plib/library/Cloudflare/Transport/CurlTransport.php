<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare\Transport;

use Noiz\CloudflareDns\Cloudflare\ApiException;

/**
 * The production HTTP transport, built on ext-curl.
 *
 * Deliberately dependency-free: no Guzzle, no Composer runtime packages. The
 * Cloudflare API surface this project needs is small, and avoiding a vendored
 * HTTP client keeps the eventual Plesk extension simple to package and ship.
 */
final class CurlTransport implements Transport
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ApiException('Unable to initialise cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ApiException('HTTP transport error: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, (string) $responseBody);
    }
}
