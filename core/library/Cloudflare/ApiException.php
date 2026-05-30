<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * Raised for any failure talking to the Cloudflare API, or for an error
 * envelope it returns ("success": false).
 */
class ApiException extends \RuntimeException
{
    /** The HTTP status that triggered the error, or 0 for a transport failure. */
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
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

        return new self('Cloudflare API error: ' . $summary, $httpStatus);
    }
}
