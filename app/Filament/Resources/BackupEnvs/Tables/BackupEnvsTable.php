<?php

namespace App\Filament\Resources\BackupEnvs\Tables;

use App\Filament\Resources\BackupEnvs\RestoreEnvActions;
use App\Jobs\RunEnvBackup;
use App\Models\BackupEnv;
use App\Models\BackupEnvRestore;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Number;

class BackupEnvsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['latestFile', 'restores']))
            ->columns([
                TextColumn::make('server_name')
                    ->label('Server')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('app_name')
                    ->label('App')
                    ->weight(FontWeight::Bold)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('latestFile.backed_up_at')
                    ->label('Ultimo backup')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('latestFile.size_bytes')
                    ->label('Dimensione')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Number::fileSize($state, precision: 1))
                    ->placeholder('—'),
                TextColumn::make('ultimo_ripristino')
                    ->label('Ultimo ripristino')
                    ->state(function (BackupEnv $record): ?string {
                        $restore = $record->restores->sortByDesc('id')->first();

                        return $restore === null ? null : match ($restore->status) {
                            BackupEnvRestore::PENDING => 'in attesa',
                            BackupEnvRestore::RUNNING => 'in corso',
                            BackupEnvRestore::DONE => 'completato',
                            default => 'fallito',
                        };
                    })
                    ->placeholder('—'),
            ])
            ->defaultSort('app_name')
            ->filters([
                SelectFilter::make('server_name')
                    ->label('Server')
                    ->options(fn (): array => BackupEnv::query()->distinct()->orderBy('server_name')->pluck('server_name', 'server_name')->all()
                        + [(string) config('backup_paths.destination_prefix') => (string) config('backup_paths.destination_prefix')])
                    ->default(fn (): string => (string) config('backup_paths.destination_prefix')),
            ])
            ->recordActions([
                ViewAction::make()->label('Apri'),
                RestoreEnvActions::forApp(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::backupAction(),
                    RestoreEnvActions::bulk(),
                ]),
            ]);
    }

    private static function backupAction(): BulkAction
    {
        return BulkAction::make('backup')
            ->label('Backup')
            ->icon('heroicon-o-key')
            ->requiresConfirmation()
            ->modalHeading('Backup dei file .env selezionati')
            ->modalDescription('Parte la copia dei file .env di questo server, anche se non sono cambiati, e il file va su R2. Quelli di un altro server si saltano.')
            ->modalSubmitActionLabel('Avvia il backup')
            ->deselectRecordsAfterCompletion()
            ->action(function (EloquentCollection|Collection|LazyCollection $records): void {
                $names = $records
                    ->filter(fn (BackupEnv $record): bool => $record->isOnThisServer())
                    ->pluck('app_name')
                    ->unique()
                    ->sort()
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
            });
    }
}
