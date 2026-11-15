<?php

/*
 * Backup anonimizzato (backup:anonymized).
 *
 * Il dump non si fa mai dal database di produzione verso l'esterno: si copia in un database temporaneo, lì si sostituiscono i dati
 * personali con dati finti (irreversibili: il sale è casuale a ogni esecuzione e non viene mai salvato), si controlla che non ne restino
 * e solo allora si esporta. Chi interviene lavora su questo file e non vede mai i dati reali.
 */

return [
    // Database che non si anonimizzano mai: schemi di sistema. `whistle` (segnalazioni) è stato abilitato su richiesta esplicita.
    'refused_databases' => ['mysql', 'sys', 'information_schema', 'performance_schema'],

    // Prefisso del database temporaneo. Si elimina sempre a fine esecuzione; solo i database con questo prefisso si possono eliminare.
    'scratch_prefix' => 'anontmp_',

    // Dove restano i file anonimizzati su questo server. Fuori da backups/databases, che si sincronizza su R2 con i backup veri.
    'local_directory' => storage_path('app/backups/anonymized'),
    'local_retention_days' => (int) env('ANON_LOCAL_RETENTION_DAYS', 7),

    // Dove vanno i file anonimizzati. Di default nel bucket R2 già usato per i backup (disco «r2»), nella sola cartella `maintenance/`,
    // che i comandi di retention e di elenco dei backup non leggono. Per usare un bucket diverso, con chiavi proprie, si impostano i
    // valori ANON_R2_*: quelli presenti prevalgono su quelli del disco «r2».
    // ATTENZIONE: in un bucket condiviso chi ha una chiave del bucket (per esempio il server di sviluppo) può leggere anche i backup veri.
    'destination' => [
        'folder' => env('ANON_R2_FOLDER', 'maintenance'),
        'prefix' => env('ANON_R2_PREFIX'),
        'bucket' => env('ANON_R2_BUCKET'),
        'endpoint' => env('ANON_R2_ENDPOINT'),
        'key' => env('ANON_R2_ACCESS_KEY_ID'),
        'secret' => env('ANON_R2_SECRET_ACCESS_KEY'),
    ],

    // Accesso noto, per chi fa manutenzione, alla copia anonimizzata: il primo utente prende questo indirizzo e tutti la stessa password.
    // L'hash è bcrypt (php artisan tinker --execute 'echo Hash::make("...");'): la password in chiaro non va mai nel .env.
    'maintenance' => [
        'email' => env('ANON_MAINTENANCE_EMAIL'),
        'password_hash' => env('ANON_MAINTENANCE_PASSWORD_HASH'),
    ],

    // Esecuzione notturna sul server di produzione (backup:anonymized-nightly). Si pianifica solo se il bucket dedicato è configurato.
    'schedule' => [
        'databases' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANON_SCHEDULE_DATABASES', 'unicosuite,backups3'))))),
        'at' => env('ANON_SCHEDULE_AT', '04:00'),
        'heartbeat_url' => env('ANON_HEARTBEAT_URL'),
        // Giorni di conservazione dei file anonimizzati nel bucket: i più vecchi si eliminano a fine run.
        'remote_retention_days' => (int) env('ANON_REMOTE_RETENTION_DAYS', 7),
    ],

    // Ripristino notturno sul server di sviluppo (backup:restore-anonymized). Va attivato SOLO lì, con ANON_RESTORE_ENABLED=true.
    'restore' => [
        'enabled' => (bool) env('ANON_RESTORE_ENABLED', false),
        'at' => env('ANON_RESTORE_AT', '05:00'),
        // Senza elenco si ripristinano gli stessi database della pianificazione notturna.
        'databases' => array_values(array_filter(array_map('trim', explode(',', (string) env('ANON_RESTORE_DATABASES', '')))))
            ?: array_values(array_filter(array_map('trim', explode(',', (string) env('ANON_SCHEDULE_DATABASES', 'unicosuite,backups3'))))),
        // «database:destinazione» separati da virgola. Senza una voce la destinazione è <database>_anon: mai il database originale.
        'targets' => env('ANON_RESTORE_TARGETS', ''),
        // Un file più vecchio di così non si ripristina: la copia notturna non è riuscita e non si fa passare per aggiornata.
        'max_age_hours' => (int) env('ANON_RESTORE_MAX_AGE_HOURS', 26),
        'heartbeat_url' => env('ANON_RESTORE_HEARTBEAT_URL'),
    ],

    // Tabelle che si svuotano: log, code, messaggi, sessioni, credenziali, segnalazioni. Ammesso il carattere jolly *.
    'truncate_tables' => [
        'activity_log', 'api_logs', 'command_runs', 'failed_jobs', 'jobs', 'job_batches', 'cache', 'cache_locks', 'sessions',
        'password_reset_tokens', 'personal_access_tokens', 'socialite_users', 'notifications',
        'chat_histories', 'chat_messages', 'ai_action_drafts', 'incoming_emails', 'process_email_replies', 'outbound_calls',
        'document_analyses', 'document_reminders', 'external_events', 'media', 'mail_accounts', 'webhook_endpoints',
        'api_clients', 'agent_api_clients', 'suspicious_activity_reports', '_etl_*',
    ],

    // Tabelle di definizione (modelli, tipi, stati, processi): non contengono dati di persone e si lasciano come sono.
    // Il controllo finale le esamina comunque.
    'keep_tables' => [
        'migrations', 'address_types', 'client_types', 'checklists', 'checklist_items', 'document_types', 'document_requirements',
        'email_templates', 'lookup_tables', 'lookup_table_column', 'menu_categories', 'modules', 'resources', 'schema_legends',
        'schema_legend_columns', 'pdf_modules', 'pdf_module_fields', 'task_document_types', 'tipoprodotto', 'tipo_prodottos',
        'tipoprodotto_sub', 'tipoprodotto_sub_constraints', 'lead_sources', 'pratica_stati', 'pratica_stati_transizioni',
        'processes', 'process_tasks', 'process_task_items', 'process_task_raci', 'process_triggers', 'oam_codes',
        'employee_types', 'employee_type_permissions', 'employee_type_resource_presets', 'company_roles', 'fornitoriroles',
        'prima_nota_configs', 'listini_provvigionali', 'listini_provvigionali_voci', 'provvigioni_rules',
    ],

    // Colonne di testo libero (text, json, blob) da lasciare intatte perché non contengono dati personali: 'tabella.colonna'.
    'keep_columns' => [],

    // Scelta esplicita per una colonna, prevale sui modelli di nome: 'tabella.colonna' => strategia.
    'columns' => [
        // Tabelle di definizione con un indirizzo e-mail dentro il testo: si svuota solo la colonna.
        'email_templates.body' => 'free_text',
        'process_tasks.escalation_rules' => 'free_text',
        // whistle: il codice di accesso al modulo pubblico non somiglia a nessun modello di nome.
        'companies.shared_passcode' => 'secret',
    ],

    // Strategie per nome di colonna, nell'ordine: vince la prima che corrisponde. Le strategie sono in App\Services\Anonymizer.
    // Una strategia non si applica a un tipo di colonna incompatibile (se ne tiene traccia nel rapporto).
    'patterns' => [
        'email' => '/(^|_)(e_?mail|pec|mail_?address)(_|$)|^email(to|from|cc|bcc)$/i',
        'phone' => '/(^|_)(telefono|tel|cell|cellulare|phone|mobile|fax|whatsapp|msisdn)(_|$)|(^|_)(wa|caller)_?number$/i',
        'fiscal_code' => '/(codice_?fiscale|(^|_)cf(_|$)|fiscal_?code|tax_?code)/i',
        'vat' => '/(partita_?iva|(^|_)p_?iva(_|$)|(^|_)vat(_?number)?(_|$))/i',
        'iban' => '/iban/i',
        'surname' => '/^(cognome|surname|cognome_?cliente|(.+_)?(last|family)_?name)$/i',
        'name' => '/^(nome|nome_?cliente|(.+_)?(first|given)_?name)$/i',
        'full_name' => '/^(name|denominazione|ragione_?sociale|nominativo|full_?name|company_?name|display_?name|intestatario|titolare|referente|username|user_?name|reclamante|segnalante|complainant|collaboratore|operatore|coordinatore|agente|cliente|fornitore|referee|auditor|entity_?name|nome_?(fornitore|agente|coge|fattura)|denominazione_?(agente|cliente|fornitore)|(verified|requested|emitted|assigned|escalated|approved|created|signed)_?(by|to)|(.+_)?(contact|employee|client|customer|agent|auditor|complainant|manager)_?name|nome_?cognome|cognome_?nome|nome_?completo)$/i',
        'birth_date' => '/(data_?(di_?)?nascita|birth_?date|date_?of_?birth|(^|_)dob(_|$)|nato_?il)/i',
        'birth_place' => '/(luogo_?(di_?)?nascita|birth_?place|place_?of_?birth|comune_?(di_?)?nascita|provincia_?(di_?)?nascita|nazione_?(di_?)?nascita)/i',
        'ip' => '/(^|_)(ip|ip_?address|user_?agent)(_|$)/i',
        'address' => '/^(((indirizzo|address|street)(_.*)?)|via|residenza|domicilio|civico|numero_?civico|(.+_)?(indirizzo|address))$/i',
        'zip' => '/^(cap|zip|zip_?code|postal_?code)$/i',
        'amount' => '/^(reddito|stipendio|salary|ral|compenso|retribuzione|income|netto_?mensile|reddito_.+|stipendio_.+|salary_.+|income_.+)$/i',
        'password' => '/^(password|passwd)$/i',
        'document_number' => '/(numero_?documento|doc(umento)?_?(n|num|number)|document_?number|passaporto|passport|carta_?identita|patente|ente_?rilascio|rilasciato_?da)/i',
        'file_name' => '/^(file_?name|filename|original_?name|nome_?file.*|file_?path|path|percorso|storage_?path|url|link|.*_url|allegato|logo|document_?name|evidence_?attachment)$/i',
        'secret' => '/(token|secret|api_?key|apikey|(^|_)otp(_|$)|two_?factor|recovery_?codes|pin(_code)?$|(^|_)(imap|smtp|db)_(host|port|database|username|password|encryption)$|_password$|(^|_)wa_media_id$)/i',
        'free_text' => '/^(note|notes|nota|descrizione|description|messaggio|message|body|testo|text|content|contenuto|oggetto|subject|email_?subject|commento|comment|motivazione|motivo|reason|payload|metadata|raw|dettagli|details|remarks|osservazioni|annotazioni|titolo|title|privacy_?data)$/i',
        'coords' => '/^(lat|lng|lon|latitude|longitude)$/i',
    ],

    // Controllo finale sul database anonimizzato: se resta anche un solo valore che somiglia a un dato personale, il file non si produce.
    // Nel rapporto compaiono tabella, colonna e numero di righe, mai i valori.
    'scan' => [
        'enabled' => true,
        'fail_on_findings' => true,
        // Falsi positivi riconosciuti: 'tabella.colonna'.
        // Identificativi di sistemi esterni lunghi 9-10 cifre, che somigliano a un numero di telefono.
        'ignore_columns' => ['documents.app_etag', 'documents.legacy_id', 'proformas.legacy_id', 'invoiceins.id_documento'],
        // Forma esatta, dall'inizio alla fine del valore, dei dati finti prodotti da questo comando: non sono dati personali e si ignorano.
        // Un valore reale che solo comincia in modo simile (es. una nota che inizia con «Nome») resta visibile al controllo.
        'fake_values' => '^(ZZZZZZ[0-9]{2}A[0-9]{2}Z[0-9]{3}Z|IT00Z[0-9]{22}|[+]39000[0-9]{7}|00[0-9]{9}|anon_[0-9a-f]{4}x[0-9a-f]{4}x[0-9a-f]{4}|Nome[0-9a-f]{6}|Cognome[0-9a-f]{6}|Soggetto [0-9a-f]{8}|Via Esempio [0-9]{1,2}|Comune Esempio|u[0-9a-f]{16}@example[.]invalid)$',
        // Espressioni regolari MySQL (ICU, senza distinzione tra maiuscole e minuscole).
        'patterns' => [
            'codice_fiscale' => ['regex' => '[A-Z]{6}[0-9]{2}[A-EHLMPRST][0-9]{2}[A-Z][0-9]{3}[A-Z]'],
            'iban' => ['regex' => 'IT[0-9]{2}[A-Z][0-9]{10}[0-9A-Z]{12}'],
            'email' => ['regex' => '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}'],
            // Il numero deve essere isolato: incollato a lettere (come in un hash esadecimale o in un codice) non è un telefono.
            'telefono' => ['regex' => '(^|[^0-9A-Za-z])([+]39|0039)?[ ]?3[0-9]{2}[ .-]?[0-9]{6,7}([^0-9A-Za-z]|$)'],
        ],
    ],
];
