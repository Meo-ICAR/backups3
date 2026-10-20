<?php

namespace App\Filament\Resources\BackupDbs\Tables;

use App\Models\BackupDb;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
