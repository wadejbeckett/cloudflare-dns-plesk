<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * Raised for any failure talking to the Cloudflare API, or for an error
 * envelope returned by it ("success": false).
 */
class ApiException extends \RuntimeException
{
    private int $httpStatus;

    /** @var array<int,mixed> the raw Cloudflare "errors" array, if any */
    private array $cloudflareErrors;

    /**
     * @param array<int,mixed> $cloudflareErrors
     */
    public function __construct(
        string $message,
        int $httpStatus = 0,
        array $cloudflareErrors = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->cloudflareErrors = $cloudflareErrors;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<int,mixed>
     */
    public function cloudflareErrors(): array
    {
        return $this->cloudflareErrors;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isAuthError(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    /**
     * Build an exception from a decoded Cloudflare error envelope.
     *
     * @param array<string,mixed> $decoded
     */
    public static function fromResponse(int $httpStatus, array $decoded): self
    {
        $errors = $decoded['errors'] ?? [];
        if (!is_array($errors)) {
            $errors = [];
        }

        $parts = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $code = $error['code'] ?? '?';
                $message = $error['message'] ?? 'unknown error';
                $parts[] = sprintf('[%s] %s', (string) $code, (string) $message);
            }
        }

        $summary = $parts !== [] ? implode('; ', $parts) : 'HTTP ' . $httpStatus;

        return new self('Cloudflare API error: ' . $summary, $httpStatus, $errors);
    }
}
