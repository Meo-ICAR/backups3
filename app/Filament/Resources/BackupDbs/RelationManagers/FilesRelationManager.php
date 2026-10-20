<?php

namespace App\Filament\Resources\BackupDbs\RelationManagers;

use App\Jobs\RestoreBackup;
use App\Models\BackupDbFile;
use App\Models\BackupDbRestore;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Backup su R2';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('restores'))
            ->columns([
                TextColumn::make('backed_up_at')
                    ->label('Data backup')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('size_bytes')
                    ->label('Dimensione')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1))
                    ->sortable(),
                TextColumn::make('r2_key')
                    ->label('File su R2')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ultimo_ripristino')
                    ->label('Ultimo ripristino')
                    ->state(function (BackupDbFile $record): ?string {
                        $restore = $record->restores->sortByDesc('id')->first();

                        return $restore ? "{$restore->target_database} — ".match ($restore->status) {
                            BackupDbRestore::PENDING => 'in attesa',
                            BackupDbRestore::RUNNING => 'in corso',
                            BackupDbRestore::DONE => 'completato',
                            default => 'fallito',
                        } : null;
                    })
                    ->placeholder('—'),
            ])
            ->defaultSort('backed_up_at', 'desc')
            ->recordActions([
                Action::make('ripristina')
                    ->label('Ripristina')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalHeading(fn (BackupDbFile $record): string => "Ripristina {$record->backupDb->database_name} del {$record->backed_up_at->format('d/m/Y H:i')}")
                    ->modalDescription('Il dump viene scaricato da R2 e caricato in un database di questo server, anche se il backup è di un altro server. Può richiedere diversi minuti.')
                    ->modalSubmitActionLabel('Avvia il ripristino')
                    ->schema(fn (BackupDbFile $record): array => [
                        Radio::make('mode')
                            ->label('Dove ripristinare')
                            ->options(fn (): array => [
                                'nuovo' => 'In un nuovo database (consigliato): il database originale non si tocca',
                            ] + ($record->backupDb->isOnThisServer() ? [
                                'originale' => "Sul database originale «{$record->backupDb->database_name}»: sostituisce le tabelle presenti",
                            ] : []))
                            ->default('nuovo')
                            ->live()
                            ->required(),
                        TextInput::make('target_database')
                            ->label('Nome del nuovo database')
                            ->default("{$record->backupDb->database_name}_restore")
                            ->regex('/^[A-Za-z0-9_]+$/')
                            ->maxLength(64)
                            ->required(fn ($get): bool => $get('mode') === 'nuovo')
                            ->visible(fn ($get): bool => $get('mode') === 'nuovo')
                            ->notIn(['mysql', 'sys', 'information_schema', 'performance_schema', $record->backupDb->database_name])
                            ->validationMessages(['not_in' => 'Per sovrascrivere l\'originale scegli l\'altra opzione.']),
                        TextInput::make('conferma')
                            ->label("Per confermare scrivi il nome del database: {$record->backupDb->database_name}")
                            ->visible(fn ($get): bool => $get('mode') === 'originale')
                            ->required(fn ($get): bool => $get('mode') === 'originale')
                            ->in([$record->backupDb->database_name])
                            ->validationMessages(['in' => 'Il nome non corrisponde.']),
                    ])
                    ->action(function (BackupDbFile $record, array $data): void {
                        // Sull'originale solo per i database di questo server: quelli di un altro server si ripristinano in un database nuovo.
                        $original = $data['mode'] === 'originale' && $record->backupDb->isOnThisServer();

                        $restore = BackupDbRestore::query()->create([
                            'backupdb_file_id' => $record->id,
                            'target_database' => $original ? $record->backupDb->database_name : $data['target_database'],
                            'overwrites_original' => $original,
                            'status' => BackupDbRestore::PENDING,
                            'requested_by' => auth()->id(),
                        ]);

                        // Dopo la risposta, nello stesso processo: non serve un worker della coda.
                        RestoreBackup::dispatchAfterResponse($restore);

                        Notification::make()
                            ->title('Ripristino avviato')
                            ->body("In «{$restore->target_database}». Lo stato è nella voce Ripristini.")
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
