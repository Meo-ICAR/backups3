<?php

namespace App\Filament\Resources\BackupDbs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BackupDbForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('server_name')
                    ->label('Server')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (): string => (string) config('backup_paths.destination_prefix'))
                    ->helperText('Nome del server: è anche il prefisso con cui il backup si trova su R2.'),
                TextInput::make('database_name')
                    ->label('Database')
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[A-Za-z0-9_]+$/')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('server_name', $get('server_name'))),
                Toggle::make('is_active')
                    ->label('Includi nel backup notturno')
                    ->default(true)
                    ->helperText('Spento: restano i backup già su R2, ma il database non viene più salvato.'),
                Toggle::make('only_if_changed')
                    ->label('Salva solo se cambiato')
                    ->default(true)
                    ->helperText('Se il dump è identico a quello già su R2 non si ricarica; resta il backup precedente.'),
            ]);
    }
}
