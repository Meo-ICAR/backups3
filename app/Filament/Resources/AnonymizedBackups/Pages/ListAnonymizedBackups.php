<?php

namespace App\Filament\Resources\AnonymizedBackups\Pages;

use App\Filament\Resources\AnonymizedBackups\AnonymizedBackupResource;
use Filament\Resources\Pages\ListRecords;

class ListAnonymizedBackups extends ListRecords
{
    protected static string $resource = AnonymizedBackupResource::class;
}
