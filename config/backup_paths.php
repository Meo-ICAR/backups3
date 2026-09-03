<?php

return [
    'databases' => [
        // Database di sistema da ignorare
        'ignored' => ['information_schema', 'performance_schema', 'mysql', 'sys'],

        // Se popolato, esegue il backup SOLO di questi DB. Se vuoto [], fa il backup di TUTTI.
        'only' => [],
    ],

    'upload_directories' => [
        // 'etichetta_r2' => 'percorso_assoluto_sulla_vps'
        'app1_storage' => '/var/www/app1/storage/app/public',
        'app2_storage' => '/var/www/app2/storage/app/public',
        'custom_uploads' => '/var/www/app3/public/uploads',
    ],

    // Quanti giorni mantenere i dump locali sulla VPS per ripristini rapidi
    'local_retention_days' => 7,
];
