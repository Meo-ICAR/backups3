<?php

namespace Tests\Feature\Anonymization;

use App\Services\AnonymizedBackupStore;

/** Bucket finto: niente rete, il «download» copia un file preparato dal test. */
class FakeAnonymizedStore extends AnonymizedBackupStore
{
    public bool $configured = true;

    /** @var array<string, int> */
    public array $pruned = [];

    /** @var array{key: string, at: \Carbon\CarbonImmutable}|null */
    public ?array $latest = null;

    public ?string $dumpFile = null;

    public ?string $latestServer = 'non chiamato';

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function latest(string $database, ?string $server = null): ?array
    {
        $this->latestServer = $server;

        return $this->latest;
    }

    public function download(string $key, string $destination): void
    {
        copy((string) $this->dumpFile, $destination);
    }

    public function prune(string $database, int $days): int
    {
        $this->pruned[$database] = $days;

        return 0;
    }
}
