<?php

namespace App\Filament\Resources\AnonymizedBackups;

use App\Filament\Resources\AnonymizedBackups\Pages\ListAnonymizedBackups;
use App\Models\AnonymizedBackup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class AnonymizedBackupResource extends Resource
{
    protected static ?string $model = AnonymizedBackup::class;

    protected static ?string $slug = 'anonimizzati';

    protected static ?string $navigationLabel = 'Anonimizzati';

    protected static ?string $modelLabel = 'Backup anonimizzato';

    protected static ?string $pluralModelLabel = 'Backup anonimizzati';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('requestedBy'))
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label('Richiesto')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('database_name')->label('Database')->searchable(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AnonymizedBackup::PENDING => 'In attesa',
                        AnonymizedBackup::RUNNING => 'In corso',
                        AnonymizedBackup::DONE => 'Completato',
                        default => 'Fallito',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        AnonymizedBackup::DONE => 'success',
                        AnonymizedBackup::RUNNING => 'info',
                        AnonymizedBackup::FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('replaced')
                    ->label('Colonne sostituite')
                    ->state(fn (AnonymizedBackup $record): ?int => $record->replacedColumnsCount())
                    ->placeholder('—'),
                IconColumn::make('scan')
                    ->label('Nessun residuo')
                    ->state(fn (AnonymizedBackup $record): ?bool => $record->scanIsClean())
                    ->boolean(),
                TextColumn::make('size_bytes')
                    ->label('Dimensione')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state, precision: 1) : '—'),
                TextColumn::make('destination')
                    ->label('Dove')
                    ->state(fn (AnonymizedBackup $record): string => $record->remote_key ? 'bucket anonimizzati' : ($record->local_path ? 'solo su questo server' : '—')),
                TextColumn::make('requestedBy.name')->label('Richiesto da')->placeholder('—'),
                TextColumn::make('error')->label('Errore')->limit(80)->tooltip(fn (AnonymizedBackup $record): ?string => $record->error)->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnonymizedBackups::route('/'),
        ];
    }
}
