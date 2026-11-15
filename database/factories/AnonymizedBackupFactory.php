<?php

namespace Database\Factories;

use App\Models\AnonymizedBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnonymizedBackup>
 */
class AnonymizedBackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_name' => 'srv-test',
            'database_name' => 'alfa',
            'status' => AnonymizedBackup::PENDING,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => [
            'status' => AnonymizedBackup::DONE,
            'size_bytes' => 2048,
            'report' => ['columns' => [['table' => 'clients', 'column' => 'email', 'strategy' => 'email']], 'scan' => ['enabled' => true, 'clean' => true, 'findings' => []]],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AnonymizedBackup::FAILED,
            'error' => 'RuntimeException: Controllo finale: residui.',
            'finished_at' => now(),
        ]);
    }
}
