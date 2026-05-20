<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * The outcome of applying a {@see SyncPlan}.
 *
 * Each record action is independent: one failing record is recorded here and
 * the remaining actions still run, so a single bad record never aborts a sync.
 */
final class SyncReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $deleted = 0;

    /** @var array<int,array{action:string,record:string,error:string}> */
    public array $errors = [];

    public function addError(string $action, string $record, \Throwable $error): void
    {
        $this->errors[] = [
            'action' => $action,
            'record' => $record,
            'error' => $error->getMessage(),
        ];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function summary(): string
    {
        $summary = sprintf(
            '%d created, %d updated, %d deleted',
            $this->created,
            $this->updated,
            $this->deleted
        );

        if ($this->hasErrors()) {
            $summary .= sprintf(', %d failed', count($this->errors));
        }

        return $summary;
    }
}
