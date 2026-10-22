<?php

namespace App\Filament\Resources\BackupDbs\Tables;

use App\Jobs\RestoreBackup;
use App\Jobs\RunDatabaseBackup;
use App\Models\BackupDb;
use App\Models\BackupDbRestore;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Number;

class BackupDbsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('server_name')
                    ->label('Server')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('database_name')
                    ->label('Database')
                    ->weight(FontWeight::Bold)
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Nel backup')
                    ->boolean(),
                TextColumn::make('files_count')
                    ->label('Backup su R2')
                    ->counts('files')
                    ->sortable(),
                TextColumn::make('latestFile.backed_up_at')
                    ->label('Ultimo backup')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('latestFile.size_bytes')
                    ->label('Dimensione')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Number::fileSize($state, precision: 1))
                    ->placeholder('—'),
                IconColumn::make('only_if_changed')
                    ->label('Solo se cambiato')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_changed_at')
                    ->label('Ultimo dump cambiato')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextColumn::make('last_checked_at')
                    ->label('Ultimo controllo')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_synced_at')
                    ->label('Lista aggiornata')
                    ->since()
                    ->placeholder('mai')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('database_name')
            ->filters([
                // Di default i database del server da cui gira l'app; «Tutti» per vederli tutti.
                SelectFilter::make('server_name')
                    ->label('Server')
                    ->options(fn (): array => BackupDb::query()->distinct()->orderBy('server_name')->pluck('server_name', 'server_name')->all()
                        + [(string) config('backup_paths.destination_prefix') => (string) config('backup_paths.destination_prefix')])
                    ->default(fn (): string => (string) config('backup_paths.destination_prefix')),
                TernaryFilter::make('is_active')->label('Nel backup'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::backupAction(),
                    self::restoreAction(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function backupAction(): BulkAction
    {
        return BulkAction::make('backup')
            ->label('Backup')
            ->icon('heroicon-o-circle-stack')
            ->requiresConfirmation()
            ->modalHeading('Backup dei database selezionati')
            ->modalDescription('Parte il dump dei database di questo server, anche se non sono cambiati, e il file va su R2. Quelli di un altro server si saltano.')
            ->modalSubmitActionLabel('Avvia il backup')
            ->deselectRecordsAfterCompletion()
            ->action(function (EloquentCollection|Collection|LazyCollection $records): void {
                $names = $records
                    ->filter(fn (BackupDb $record): bool => $record->isOnThisServer())
                    ->pluck('database_name')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($names === []) {
                    Notification::make()
                        ->title('Nessun database di questo server')
                        ->body('Il backup si esegue solo sui database che girano qui.')
                        ->danger()
                        ->send();

                    return;
                }

                RunDatabaseBackup::dispatchAfterResponse($names);

                Notification::make()
                    ->title('Backup avviato')
                    ->body(implode(', ', $names))
                    ->success()
                    ->send();
            });
    }

    private static function restoreAction(): BulkAction
    {
        $action = BulkAction::make('ripristina')
            ->label('Ripristina')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->modalHeading('Ripristina i database selezionati')
            ->modalDescription('Per ciascuno si usa l\'ultimo backup su R2. Senza un backup, il database si salta.')
            ->modalSubmitActionLabel('Avvia il ripristino')
            ->deselectRecordsAfterCompletion();

        return $action->schema(fn (): array => [
            Radio::make('mode')
                ->label('Dove ripristinare')
                ->options(function () use ($action): array {
                    $options = [
                        'nuovo' => 'In un nuovo database per ciascuno, con suffisso _restore. L\'originale non si tocca',
                    ];

                    $records = $action->getSelectedRecords();
                    if ($records->isNotEmpty() && $records->every(fn (BackupDb $record): bool => $record->isOnThisServer())) {
                        $options['originale'] = 'Sui database originali di questo server: sostituisce le tabelle presenti';
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
            $original = ($data['mode'] ?? '') === 'originale';

            if ($original && $records->contains(fn (BackupDb $record): bool => ! $record->isOnThisServer())) {
                Notification::make()
                    ->title('Ripristino sull\'originale non disponibile')
                    ->body('Un database di un altro server si ripristina solo in un database nuovo.')
                    ->danger()
                    ->send();

                return;
            }

            if ($records instanceof EloquentCollection) {
                $records->load('latestFile');
            }

            $started = [];
            $skipped = [];
            $targets = [];

            foreach ($records as $record) {
                $file = $record->latestFile;
                if ($file === null) {
                    $skipped[] = $record->database_name;

                    continue;
                }

                $target = $original ? $record->database_name : $record->database_name.'_restore';
                $forbidden = ['mysql', 'sys', 'information_schema', 'performance_schema'];

                if (
                    strlen($target) > 64
                    || ! preg_match('/^[A-Za-z0-9_]+$/', $target)
                    || in_array($target, $forbidden, true)
                    || (! $original && $target === $record->database_name)
                    || isset($targets[$target])
                ) {
                    $skipped[] = $record->database_name;

                    continue;
                }

                $targets[$target] = true;

                $restore = BackupDbRestore::query()->create([
                    'backupdb_file_id' => $file->id,
                    'target_database' => $target,
                    'overwrites_original' => $original,
                    'status' => BackupDbRestore::PENDING,
                    'requested_by' => auth()->id(),
                ]);

                RestoreBackup::dispatchAfterResponse($restore);
                $started[] = $record->database_name.' → '.$target;
            }

            if ($started === []) {
                Notification::make()
                    ->title('Nessun ripristino avviato')
                    ->body($skipped === [] ? 'Nessun backup selezionato.' : 'Senza backup su R2: '.implode(', ', $skipped))
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
}
