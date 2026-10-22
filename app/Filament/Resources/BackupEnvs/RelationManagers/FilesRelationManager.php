<?php

namespace App\Filament\Resources\BackupEnvs\RelationManagers;

use App\Filament\Resources\BackupEnvs\RestoreEnvActions;
use App\Models\BackupEnvFile;
use App\Models\BackupEnvRestore;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['backupEnv', 'restores']))
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
                    ->state(function (BackupEnvFile $record): ?string {
                        $restore = $record->restores->sortByDesc('id')->first();

                        return $restore === null ? null : $restore->target_file.' — '.match ($restore->status) {
                            BackupEnvRestore::PENDING => 'in attesa',
                            BackupEnvRestore::RUNNING => 'in corso',
                            BackupEnvRestore::DONE => 'completato',
                            default => 'fallito',
                        };
                    })
                    ->placeholder('—'),
            ])
            ->defaultSort('backed_up_at', 'desc')
            ->recordActions([
                RestoreEnvActions::forFile(),
            ]);
    }
}
