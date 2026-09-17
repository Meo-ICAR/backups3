<?php

return [
    'databases' => [
        // Database di sistema da ignorare
        'ignored' => ['information_schema', 'performance_schema', 'mysql', 'sys'],

        // Se popolato, esegue il backup SOLO di questi DB. Se vuoto [], fa il backup di TUTTI.
        'only' => ['proforma', 'unicooam', 'unicobpm', 'unicoloan', 'whistle'],
    ],

    // Credenziali usate per il dump (utente separato, idealmente in sola lettura)
    'mysql' => [
        'host' => env('DB_BACKUP_HOST', '127.0.0.1'),
        'username' => env('DB_BACKUP_USERNAME'),
        'password' => env('DB_BACKUP_PASSWORD'),
    ],

    'upload_directories' => [
        // 'etichetta_r2' => 'percorso_assoluto_sulla_vps'
        //    'app1_storage' => '/var/www/app1/storage/app/public',
        //    'app2_storage' => '/var/www/app2/storage/app/public',
        //     'custom_uploads' => '/var/www/app3/public/uploads',

        // whitelist (UnicoWhistle): allegati delle segnalazioni (già cifrati
        // a livello applicativo prima di essere scritti su disco, vedi
        // PublicReportForm), collection Media Library 'evidence' sul disco
        // 'private', che risiede in storage/app/private.
        'whitelist_evidence' => '/var/www/html/whitelist/storage/app/private',
    ],

    // Quanti giorni mantenere i dump locali sulla VPS per ripristini rapidi
    'local_retention_days' => 7,

    // URL di heartbeat (es. Healthchecks.io) chiamato al termine del run notturno.
    // Al successo viene chiamato cosi' com'e'; al fallimento con suffisso /fail
    'heartbeat_url' => env('HEARTBEAT_URL'),

    // Timeout (secondi) per i processi 'aws s3 sync' verso R2
    'sync_timeout' => env('BACKUP_SYNC_TIMEOUT', 3600),
];
