<?php

namespace App\Filament\Resources\BackupDbs;

use App\Filament\Resources\BackupDbs\Pages\CreateBackupDb;
use App\Filament\Resources\BackupDbs\Pages\EditBackupDb;
use App\Filament\Resources\BackupDbs\Pages\ListBackupDbs;
use App\Filament\Resources\BackupDbs\RelationManagers\FilesRelationManager;
use App\Filament\Resources\BackupDbs\Schemas\BackupDbForm;
use App\Filament\Resources\BackupDbs\Tables\BackupDbsTable;
use App\Models\BackupDb;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BackupDbResource extends Resource
{
    protected static ?string $model = BackupDb::class;

    protected static ?string $slug = 'database';

    protected static ?string $navigationLabel = 'Database';

    protected static ?string $modelLabel = 'Database';

    protected static ?string $pluralModelLabel = 'Database';

    protected static ?string $recordTitleAttribute = 'database_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function form(Schema $schema): Schema
    {
        return BackupDbForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackupDbsTable::configure($table);
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
            'index' => ListBackupDbs::route('/'),
            'create' => CreateBackupDb::route('/create'),
            'edit' => EditBackupDb::route('/{record}/edit'),
        ];
    }
}
