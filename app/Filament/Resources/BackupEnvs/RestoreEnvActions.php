<?php

namespace App\Filament\Resources\BackupEnvs;

use App\Jobs\RestoreEnvFile;
use App\Models\BackupEnv;
use App\Models\BackupEnvFile;
use App\Models\BackupEnvRestore;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class RestoreEnvActions
{
    public static function forFile(): Action
    {
        return Action::make('ripristina')
            ->label('Ripristina')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading(fn (BackupEnvFile $record): string => "Ripristina il .env di {$record->backupEnv->app_name}")
            ->modalDescription('Si scarica la copia attuale su R2. Il contenuto non viene mostrato. Di norma si scrive .env.restore e il file in uso resta com\'è.')
            ->modalSubmitActionLabel('Avvia il ripristino')
            ->schema(fn (BackupEnvFile $record): array => self::fields($record->backupEnv))
            ->action(function (BackupEnvFile $record, array $data): void {
                $overwrite = ($data['mode'] ?? '') === 'originale' && $record->backupEnv->isOnThisServer();

                if (($data['mode'] ?? '') === 'originale' && ! $overwrite) {
                    Notification::make()
                        ->title('Ripristino sul file in uso non disponibile')
                        ->body('Il .env di un altro server si ripristina solo in .env.restore.')
                        ->danger()
                        ->send();

                    return;
                }

                self::start($record, $overwrite);

                Notification::make()
                    ->title('Ripristino avviato')
                    ->body($record->backupEnv->app_name.' → '.($overwrite ? '.env' : '.env.restore').'. Lo stato è nella voce Ripristini env.')
                    ->success()
                    ->send();
            });
    }

    public static function forApp(): Action
    {
        return Action::make('ripristina')
            ->label('Ripristina')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading(fn (BackupEnv $record): string => "Ripristina il .env di {$record->app_name}")
            ->modalDescription('Si usa la copia attuale su R2. Il contenuto non viene mostrato. Di norma si scrive .env.restore e il file in uso resta com\'è.')
            ->modalSubmitActionLabel('Avvia il ripristino')
            ->schema(fn (BackupEnv $record): array => self::fields($record))
            ->action(function (BackupEnv $record, array $data): void {
                $file = $record->latestFile;

                if ($file === null) {
                    Notification::make()->title('Nessuna copia su R2')->warning()->send();

                    return;
                }

                $overwrite = ($data['mode'] ?? '') === 'originale' && $record->isOnThisServer();
                self::start($file, $overwrite);

                Notification::make()
                    ->title('Ripristino avviato')
                    ->body($record->app_name.' → '.($overwrite ? '.env' : '.env.restore'))
                    ->success()
                    ->send();
            });
    }

    public static function bulk(): BulkAction
    {
        $action = BulkAction::make('ripristina')
            ->label('Ripristina')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading('Ripristina i file .env selezionati')
            ->modalDescription('Per ciascuno si usa la copia attuale su R2. Senza una copia, l\'app si salta. Il contenuto non viene mostrato.')
            ->modalSubmitActionLabel('Avvia il ripristino')
            ->deselectRecordsAfterCompletion();

        return $action->schema(fn (): array => [
            Radio::make('mode')
                ->label('Dove ripristinare')
                ->options(function () use ($action): array {
                    $options = [
                        'nuovo' => 'In un file .env.restore per ciascuna app. Il .env in uso non si tocca',
                    ];

                    $records = $action->getSelectedRecords();
                    if ($records->isNotEmpty() && $records->every(fn (BackupEnv $record): bool => $record->isOnThisServer())) {
                        $options['originale'] = 'Sui file .env in uso di questo server: sostituisce quelli presenti';
                    }

                    return $options;
                })
                ->default('nuovo')
                ->live()
                ->required(),
            TextInput::make('conferma')
                ->label('Per confermare scrivi: sovrascrivi')
                ->visible(fn ($get): bool => $get('mode') === 'originale')
                ->required(fn ($get): bool => $get('mode') === 'originale')
                ->in(['sovrascrivi'])
                ->validationMessages(['in' => 'Scrivi sovrascrivi per confermare.']),
        ])->action(function (EloquentCollection|Collection|LazyCollection $records, array $data): void {
            $overwrite = ($data['mode'] ?? '') === 'originale';

            if ($overwrite && $records->contains(fn (BackupEnv $record): bool => ! $record->isOnThisServer())) {
                Notification::make()
                    ->title('Ripristino sul file in uso non disponibile')
                    ->body('Un .env di un altro server si ripristina solo in .env.restore.')
                    ->danger()
                    ->send();

                return;
            }

            if ($records instanceof EloquentCollection) {
                $records->load('latestFile');
            }

            $started = [];
            $skipped = [];

            foreach ($records as $record) {
                $file = $record->latestFile;
                if ($file === null) {
                    $skipped[] = $record->app_name;

                    continue;
                }

                self::start($file, $overwrite);
                $started[] = $record->app_name.' → '.($overwrite ? '.env' : '.env.restore');
            }

            if ($started === []) {
                Notification::make()
                    ->title('Nessun ripristino avviato')
                    ->body($skipped === [] ? 'Nessun backup selezionato.' : 'Senza copia su R2: '.implode(', ', $skipped))
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Ripristino avviato')
                ->body(implode(', ', $started).($skipped === [] ? '' : '. Saltati: '.implode(', ', $skipped)))
                ->success()
                ->send();
        });
    }

    /**
     * @return array<int, Radio|TextInput>
     */
    private static function fields(BackupEnv $app): array
    {
        return [
            Radio::make('mode')
                ->label('Dove ripristinare')
                ->options(fn (): array => [
                    'nuovo' => 'In un file .env.restore: il .env in uso non si tocca',
                ] + ($app->isOnThisServer() ? [
                    'originale' => "Sul file .env di «{$app->app_name}»: sostituisce quello in uso su questo server",
                ] : []))
                ->default('nuovo')
                ->live()
                ->required(),
            TextInput::make('conferma')
                ->label("Per confermare scrivi il nome dell'app: {$app->app_name}")
                ->visible(fn ($get): bool => $get('mode') === 'originale')
                ->required(fn ($get): bool => $get('mode') === 'originale')
                ->in([$app->app_name])
                ->validationMessages(['in' => 'Il nome non corrisponde.']),
        ];
    }

    private static function start(BackupEnvFile $file, bool $overwrite): void
    {
        $restore = BackupEnvRestore::query()->create([
            'backupenv_file_id' => $file->id,
            'target_file' => $overwrite ? BackupEnvRestore::ORIGINAL : BackupEnvRestore::COPY,
            'overwrites_original' => $overwrite,
            'status' => BackupEnvRestore::PENDING,
            'requested_by' => auth()->id(),
        ]);

        RestoreEnvFile::dispatchAfterResponse($restore);
    }
}
