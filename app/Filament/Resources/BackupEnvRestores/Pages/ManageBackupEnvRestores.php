<?php

namespace App\Filament\Resources\BackupEnvRestores\Pages;

use App\Filament\Resources\BackupEnvRestores\BackupEnvRestoreResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBackupEnvRestores extends ManageRecords
{
    protected static string $resource = BackupEnvRestoreResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
