<?php

namespace App\Filament\Resources\BackupDbRestores;

use App\Filament\Resources\BackupDbRestores\Pages\ListBackupDbRestores;
use App\Models\BackupDbRestore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupDbRestoreResource extends Resource
{
    protected static ?string $model = BackupDbRestore::class;

    protected static ?string $slug = 'ripristini';

    protected static ?string $navigationLabel = 'Ripristini';

    protected static ?string $modelLabel = 'Ripristino';

    protected static ?string $pluralModelLabel = 'Ripristini';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['file.backupDb', 'requestedBy']))
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label('Richiesto')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('file.backupDb.database_name')->label('Database'),
                TextColumn::make('file.backed_up_at')->label('Backup del')->dateTime('d/m/Y H:i'),
                TextColumn::make('target_database')->label('Ripristinato in')->searchable(),
                TextColumn::make('overwrites_original')
                    ->label('Originale')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'sovrascritto' : 'no')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BackupDbRestore::PENDING => 'In attesa',
                        BackupDbRestore::RUNNING => 'In corso',
                        BackupDbRestore::DONE => 'Completato',
                        default => 'Fallito',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        BackupDbRestore::DONE => 'success',
                        BackupDbRestore::RUNNING => 'info',
                        BackupDbRestore::FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requestedBy.name')->label('Richiesto da')->placeholder('—'),
                TextColumn::make('error')->label('Errore')->limit(80)->tooltip(fn (BackupDbRestore $record): ?string => $record->error)->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackupDbRestores::route('/'),
        ];
    }
}
