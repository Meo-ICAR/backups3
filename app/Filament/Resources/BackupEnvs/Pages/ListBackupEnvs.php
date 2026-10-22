<?php

namespace App\Filament\Resources\BackupEnvs\Pages;

use App\Filament\Resources\BackupEnvs\BackupEnvResource;
use App\Jobs\RunEnvBackup;
use App\Models\BackupEnv;
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
            Action::make('backup')
                ->label('Backup')
                ->icon('heroicon-o-key')
                ->requiresConfirmation()
                ->modalHeading('Backup dei file .env di questo server')
                ->modalDescription('Parte la copia dei file .env di questo server, anche se non sono cambiati, e il file va su R2. Quelli di un altro server si saltano.')
                ->modalSubmitActionLabel('Avvia il backup')
                ->action(function (): void {
                    $names = BackupEnv::query()
                        ->where('server_name', config('backup_paths.destination_prefix'))
                        ->orderBy('app_name')
                        ->pluck('app_name')
                        ->unique()
                        ->values()
                        ->all();

                    if ($names === []) {
                        Notification::make()
                            ->title('Nessun file .env di questo server')
                            ->body('Il backup si esegue solo sulle app che girano qui.')
                            ->danger()
                            ->send();

                        return;
                    }

                    RunEnvBackup::dispatchAfterResponse($names);

                    Notification::make()
                        ->title('Backup avviato')
                        ->body(implode(', ', $names))
                        ->success()
                        ->send();
                }),
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
