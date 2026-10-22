<?php

namespace App\Filament\Resources\BackupEnvs;

use App\Filament\Resources\BackupEnvs\Pages\ListBackupEnvs;
use App\Filament\Resources\BackupEnvs\Pages\ViewBackupEnv;
use App\Filament\Resources\BackupEnvs\RelationManagers\FilesRelationManager;
use App\Filament\Resources\BackupEnvs\Schemas\BackupEnvInfolist;
use App\Filament\Resources\BackupEnvs\Tables\BackupEnvsTable;
use App\Models\BackupEnv;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BackupEnvResource extends Resource
{
    protected static ?string $model = BackupEnv::class;

    protected static ?string $slug = 'env';

    protected static ?string $navigationLabel = 'Env';

    protected static ?string $modelLabel = 'File env';

    protected static ?string $pluralModelLabel = 'File env';

    protected static ?string $recordTitleAttribute = 'app_name';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return BackupEnvInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackupEnvsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackupEnvs::route('/'),
            'view' => ViewBackupEnv::route('/{record}'),
        ];
    }
}
