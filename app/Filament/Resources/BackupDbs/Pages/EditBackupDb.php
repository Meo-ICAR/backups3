<?php

namespace App\Filament\Resources\BackupDbs\Pages;

use App\Filament\Resources\BackupDbs\BackupDbResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBackupDb extends EditRecord
{
    protected static string $resource = BackupDbResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
