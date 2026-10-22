<?php

namespace App\Filament\Resources\BackupEnvs\Pages;

use App\Filament\Resources\BackupEnvs\BackupEnvResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListBackupEnvs extends ListRecords
{
    protected static string $resource = BackupEnvResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sincronizza')
                ->label('Aggiorna da R2')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $exit = Artisan::call('backup:sync-remote');
                    $output = trim(Artisan::output());

                    Notification::make()
                        ->title($exit === 0 ? 'Elenco backup aggiornato' : 'Aggiornamento da R2 non riuscito')
                        ->body($output)
                        ->color($exit === 0 ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }
}
