<?php

namespace App\Filament\Resources\BackupEnvRestores;

use App\Filament\Resources\BackupEnvRestores\Pages\ManageBackupEnvRestores;
use App\Models\BackupEnvRestore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupEnvRestoreResource extends Resource
{
    protected static ?string $model = BackupEnvRestore::class;

    protected static ?string $slug = 'ripristini-env';

    protected static ?string $navigationLabel = 'Ripristini env';

    protected static ?string $modelLabel = 'Ripristino env';

    protected static ?string $pluralModelLabel = 'Ripristini env';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['file.backupEnv', 'requestedBy']))
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label('Richiesto')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('file.backupEnv.app_name')->label('App'),
                TextColumn::make('file.backed_up_at')->label('Backup del')->dateTime('d/m/Y H:i'),
                TextColumn::make('target_file')->label('Scritto in'),
                TextColumn::make('overwrites_original')
                    ->label('File in uso')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'sostituito' : 'no')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BackupEnvRestore::PENDING => 'In attesa',
                        BackupEnvRestore::RUNNING => 'In corso',
                        BackupEnvRestore::DONE => 'Completato',
                        default => 'Fallito',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        BackupEnvRestore::DONE => 'success',
                        BackupEnvRestore::RUNNING => 'info',
                        BackupEnvRestore::FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requestedBy.name')->label('Richiesto da')->placeholder('—'),
                TextColumn::make('error')->label('Errore')->limit(80)->tooltip(fn (BackupEnvRestore $record): ?string => $record->error)->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBackupEnvRestores::route('/'),
        ];
    }
}
