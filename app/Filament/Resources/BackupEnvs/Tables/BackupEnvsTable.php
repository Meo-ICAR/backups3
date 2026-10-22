<?php

namespace App\Filament\Resources\BackupEnvs\Tables;

use App\Filament\Resources\BackupEnvs\RestoreEnvActions;
use App\Models\BackupEnv;
use App\Models\BackupEnvRestore;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
                    RestoreEnvActions::bulk(),
                ]),
            ]);
    }
}
