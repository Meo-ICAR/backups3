<?php

namespace App\Filament\Resources\BackupEnvs\Pages;

use App\Filament\Resources\BackupEnvs\BackupEnvResource;
use App\Services\EnvBackupContents;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

class ViewBackupEnv extends ViewRecord
{
    protected static string $resource = BackupEnvResource::class;

    public ?string $envContents = null;

    public ?string $envError = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        try {
            $this->envContents = app(EnvBackupContents::class)->for($this->getRecord());
        } catch (RuntimeException $exception) {
            $this->envError = $exception->getMessage();
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
