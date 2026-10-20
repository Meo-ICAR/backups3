<?php

namespace App\Filament\Resources\BackupDbRestores\Pages;

use App\Filament\Resources\BackupDbRestores\BackupDbRestoreResource;
use Filament\Resources\Pages\ListRecords;

class ListBackupDbRestores extends ListRecords
{
    protected static string $resource = BackupDbRestoreResource::class;
}
