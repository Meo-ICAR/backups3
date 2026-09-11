<?php

return [
    'databases' => [
        // Database di sistema da ignorare
        'ignored' => ['information_schema', 'performance_schema', 'mysql', 'sys'],

        // Se popolato, esegue il backup SOLO di questi DB. Se vuoto [], fa il backup di TUTTI.
        'only' => ['unicooam', 'unicogdpr'],

        // Credenziali usate per il dump (utente separato, idealmente in sola lettura)
        'connection' => [
            'host' => env('DB_BACKUP_HOST', '127.0.0.1'),
            'username' => env('DB_BACKUP_USERNAME'),
            'password' => env('DB_BACKUP_PASSWORD'),
        ],
    ],

    'upload_directories' => [
        // 'etichetta_r2' => 'percorso_assoluto_sulla_vps'
        'app1_storage' => '/var/www/app1/storage/app/public',
        'app2_storage' => '/var/www/app2/storage/app/public',
        'custom_uploads' => '/var/www/app3/public/uploads',
    ],

    // Quanti giorni mantenere i dump locali sulla VPS per ripristini rapidi
    'local_retention_days' => 7,

    // Credenziali e destinazione Cloudflare R2 (via AWS CLI, compatibile S3)
    'r2' => [
        'access_key_id' => env('R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('R2_SECRET_ACCESS_KEY'),
        'bucket' => env('R2_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),
    ],

    // URL di heartbeat (es. Healthchecks.io) chiamato al termine di un run notturno riuscito
    'heartbeat_url' => env('HEARTBEAT_URL'),

    // Timeout (secondi) per i processi di sincronizzazione verso R2
    'sync_timeout' => env('BACKUP_SYNC_TIMEOUT', 3600),
];
