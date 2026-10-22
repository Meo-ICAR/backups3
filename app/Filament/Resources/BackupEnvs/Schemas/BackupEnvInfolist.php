<?php

namespace App\Filament\Resources\BackupEnvs\Schemas;

use App\Filament\Resources\BackupEnvs\Pages\ViewBackupEnv;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class BackupEnvInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('server_name')->label('Server'),
                TextEntry::make('app_name')->label('App'),
                TextEntry::make('latestFile.backed_up_at')->label('Ultimo backup')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('latestFile.size_bytes')
                    ->label('Dimensione')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Number::fileSize($state, precision: 1))
                    ->placeholder('—'),
                TextEntry::make('latestFile.r2_key')->label('File su R2')->placeholder('—'),
                ViewEntry::make('contenuto')
                    ->label('Contenuto del .env')
                    ->view('filament.backup-env-contents', function (ViewEntry $entry): array {
                        $page = $entry->getLivewire();

                        return [
                            'contents' => $page instanceof ViewBackupEnv ? $page->envContents : null,
                            'error' => $page instanceof ViewBackupEnv ? $page->envError : 'Contenuto non disponibile.',
                        ];
                    })
                    ->columnSpanFull(),
            ]);
    }
}
