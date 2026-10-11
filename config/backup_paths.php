<?php

return [
    'databases' => [
        // Database di sistema da ignorare
        'ignored' => ['information_schema', 'performance_schema', 'mysql', 'sys'],

        // Database vecchi con viste non valide (rimandano a tabelle che non esistono più): il dump prosegue con --force e vale se supera
        // i controlli di integrità; nell'esito per UnicoBPM resta un avviso. Per gli altri un errore di dump è un fallimento.
        'tolerate_errors' => ['proforma'],

        // Se popolato, esegue il backup SOLO di questi DB. Se vuoto [], fa il backup di TUTTI.
        //  - database nuovi delle app collegate a unico-core: unicooam_core (tabelle del pacchetto, condivise con unicobpm), unicoloan_core,
        //    proforma_core, unicobpm_dev (processi), unicoagent;
        //  - database originali: restano nel backup finché non vengono dismessi.
        'only' => [
            'unicooam_core', 'unicoloan_core', 'proforma_core', 'unicobpm_dev', 'unicoagent',
            'proforma', 'unicooam', 'unicobpm', 'unicoloan', 'whistle',
        ],
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

    // Timeout (secondi) per i processi di sincronizzazione (aws s3 sync / rsync)
    'sync_timeout' => env('BACKUP_SYNC_TIMEOUT', 3600),

    // Destinazioni di sincronizzazione attive: elenco separato da virgole tra "r2" e "vps".
    // Esempi: "r2" (solo Cloudflare R2), "vps" (solo VPS remota), "r2,vps" (entrambe).
    'sync_targets' => array_filter(array_map('trim', explode(',', env('BACKUP_SYNC_TARGET', 'r2')))),

    // Sottocartella (su R2 e sulla VPS remota) sotto cui finiscono i backup di questo server,
    // per distinguere più server/clienti che scrivono sulla stessa destinazione condivisa.
    // Default: hostname della macchina. Override manuale con BACKUP_DESTINATION_PREFIX in .env.
    'destination_prefix' => env('BACKUP_DESTINATION_PREFIX', gethostname()),

    // VPS remota di destinazione dei backup via rsync/ssh (alternativa/complemento a R2)
    'remote' => [
        'host' => env('REMOTE_BACKUP_HOST'),
        'user' => env('REMOTE_BACKUP_USER'),
        'password' => env('REMOTE_BACKUP_PASSWORD'),
        'path' => env('REMOTE_BACKUP_PATH', '/home/ubuntu/backups'),
        'port' => env('REMOTE_BACKUP_PORT', 22),
    ],

    // UnicoBPM: a fine run notturno l'esito (ok/fallito, database salvati, codici di uscita) gli viene comunicato come evento `backup.esito`
    // (POST {url}/api/events, chiave condivisa del bridge). Senza indirizzo o chiave non si invia nulla. Un fallimento qui non cambia l'esito del backup.
    'bpm' => [
        'url' => env('BPM_API_URL'),
        'key' => env('BPM_BRIDGE_API_KEY'),
        'timeout' => env('BPM_TIMEOUT', 10),
    ],
];
