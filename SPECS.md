# BackupS3 — Specifiche di progetto

Applicazione Laravel headless (nessuna UI web) che esegue backup notturni di
database MySQL e cartelle di upload verso **Cloudflare R2** (storage
S3-compatibile). Pensata per girare su una VPS via cron/scheduler Laravel.

## Scopo

1. Fare dump gzip di uno o più database MySQL, verificarne l'integrità
   localmente, poi sincronizzarli su R2.
2. Sincronizzare in modo incrementale cartelle di upload (storage di altre
   app sulla stessa VPS) su R2.
3. Applicare una politica di retention/rotazione sui backup presenti su R2
   (giornaliera → settimanale → mensile → eliminazione).
4. Segnalare l'esito del run notturno a un servizio di heartbeat esterno
   (es. Healthchecks.io).

## Architettura

Tre comandi Artisan, nessun controller/route HTTP, nessun modello di
dominio oltre lo scaffold di default di Laravel:

| Comando            | File                                          | Responsabilità |
|---------------------|-----------------------------------------------|----------------|
| `backup:databases`  | `app/Console/Commands/BackupDatabases.php`    | Dump MySQL → gzip → integrity check → sync su R2 → pulizia dump locali vecchi |
| `backup:uploads`    | `app/Console/Commands/SyncUploads.php`        | `aws s3 sync` incrementale di cartelle configurate verso R2 |
| `backup:clean`      | `app/Console/Commands/CleanBackups.php`       | Lista oggetti `databases/` su R2 ed elimina quelli fuori retention |

Orchestrazione in `routes/console.php`: uno `Schedule::call()` chiamato
`backup:nightly-run`, alle 02:00, con `withoutOverlapping()` e
`onOneServer()` (richiede `CACHE_STORE` con driver condiviso — qui
`database`, va bene).

### Perché AWS CLI e non Flysystem S3

Le sincronizzazioni usano il binario `aws` (via `Process`) invece del
filesystem S3 di Laravel. È una scelta deliberata: `aws s3 sync` fa un
diff efficiente lato client tra locale e bucket (skip dei file invariati),
cosa che Flysystem non offre nativamente senza reimplementare la logica di
diffing in PHP. Il prezzo è una dipendenza di sistema esterna (`aws` CLI
deve essere installato e nel `PATH` del server, incluso quello usato dal
cron).

### Configurazione

Tutte le variabili d'ambiente rilevanti sono lette **solo** dentro
`config/backup_paths.php`, mai con `env()` direttamente nei comandi. Questo
è un requisito, non uno stile: `env()` chiamato fuori dai file di config
ritorna `null` dopo `php artisan config:cache`, che è quasi certamente in
uso in produzione. Qualsiasi nuova variabile d'ambiente va aggiunta a
`config/backup_paths.php` e a `.env.example`, mai letta con `env()` in un
Command/Job/Controller.

Struttura di `config/backup_paths.php`:

- `databases.ignored` / `databases.only` — quali DB includere nel backup.
- `databases.connection` — credenziali MySQL dedicate al dump (utente a
  privilegi minimi, vedi sotto).
- `upload_directories` — mappa `etichetta => percorso assoluto` sincronizzata
  su `s3://{bucket}/uploads/{etichetta}`.
- `local_retention_days` — quanti giorni tenere i dump locali sulla VPS.
- `r2.*` — credenziali e bucket/endpoint Cloudflare R2.
- `heartbeat_url` — endpoint di monitoraggio (chiamato anche con suffisso
  `/fail` in caso di errore).
- `sync_timeout` — timeout (secondi) dei processi `aws s3 sync`.

## Convenzioni per il layout su R2

```
s3://{bucket}/databases/{db_name}/{db_name}_{Y-m-d_H-i-s}.sql.gz
s3://{bucket}/uploads/{etichetta}/...   (speculare alla cartella locale)
```

`backup:clean` si basa su questo pattern per estrarre la data dal nome file
(regex `_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql\.gz$`). Se cambia il
formato del nome file del dump, va aggiornata anche questa regex.

## Politica di retention (in `CleanBackups::shouldDelete`)

1. **0–14 giorni**: tutti i backup conservati (granularità giornaliera).
2. **14 giorni – 12 mesi**: solo domenica o primo del mese (settimanale).
3. **12–36 mesi**: solo primo del mese (mensile).
4. **> 36 mesi**: eliminati sempre.

Questa logica è pura e testata in
`tests/Unit/Console/Commands/CleanBackupsRetentionTest.php`. Qualsiasi
modifica alla policy deve passare da lì prima di essere deployata — è il
modo più economico per evitare di cancellare backup per errore.

**Nota**: la retention si applica solo ai backup dei database
(`databases/`). Gli upload sincronizzati non vengono mai rimossi da R2
(nessun `--delete` su `aws s3 sync`): è una sincronizzazione additiva, non
uno specchio 1:1. Se in futuro serve anche la cancellazione degli upload
rimossi localmente, va deciso esplicitamente — non abilitarlo per errore,
significherebbe perdere per sempre file cancellati per sbaglio in locale.

## Sicurezza — requisiti fermi

- **Utente MySQL dedicato al backup** (`DB_BACKUP_*`): deve avere solo i
  privilegi minimi necessari al dump (`SELECT`, `LOCK TABLES`, `SHOW VIEW`,
  `PROCESS`, eventualmente `EVENT`/`TRIGGER` se servono). Non deve mai
  essere lo stesso utente `root` applicativo.
- Le credenziali (DB, R2) vengono passate ai processi esterni via variabili
  d'ambiente (`Process::env()`), mai come argomenti CLI in chiaro — evita
  che compaiano in `ps aux` o nei log dei processi.
- `spatie/db-dumper` scrive le credenziali MySQL in un file temporaneo
  passato a `mysqldump` (`--defaults-extra-file`), non in argomenti di
  processo: comportamento corretto, non modificarlo con `->addExtraOption`
  passando `-p{password}` o simili.
- `.env` è in `.gitignore` — verificato, resta così. Non committare mai
  segreti in `config/backup_paths.php` (deve restare solo `env()` con
  default non sensibili).
- I backup contengono dati soggetti a GDPR (es. DB `unicogdpr`): valutare
  se serve **cifratura at-rest lato client** (es. `gpg --encrypt` prima
  dell'upload) oltre al TLS in transito e alla cifratura lato server di R2,
  specialmente se l'accesso al bucket R2 non è strettamente limitato.
- Qualunque credenziale che sia comparsa in chiaro in un log, in un
  terminale condiviso o in una chat (incluse sessioni di pair-programming
  con AI agent) va considerata potenzialmente esposta e **ruotata**, non
  solo rimossa dalla history.

## Affidabilità — requisiti fermi

- Ogni comando deve ritornare `self::FAILURE` se una qualunque operazione
  significativa fallisce (dump, sync, delete). Non deve mai ritornare
  `self::SUCCESS` "ottimisticamente" ignorando errori intermedi — è già
  successo (`backup:uploads` prima del fix ritornava sempre `SUCCESS`) e ha
  reso inutile l'heartbeat come segnale di allarme.
- Ogni processo esterno lanciato via `Process` che può girare più di
  qualche secondo (sync R2 di dump o upload di grosse dimensioni) deve
  avere un `->timeout()` esplicito coerente con `backup_paths.sync_timeout`.
  Il timeout di default di Laravel (60s) è troppo corto per sync reali.
- Il job notturno non deve mai sovrapporsi a un run precedente ancora in
  corso: `withoutOverlapping()` sullo `Schedule::call()` (richiede `->name()`
  esplicito per un closure-based schedule) più `onOneServer()` se in futuro
  la VPS diventa multi-nodo.
- In caso di fallimento del run notturno, l'errore va sia loggato
  (`Log::error` con dettaglio degli exit code per comando) sia segnalato
  all'heartbeat con l'endpoint di fallimento, non silenziato.

## Test

- `tests/Unit/Console/Commands/CleanBackupsRetentionTest.php` — copre la
  policy di retention pura (`shouldDelete`), senza I/O.
- `tests/Feature/Console/Commands/SyncUploadsCommandTest.php` — copre
  `backup:uploads` con `Process::fake()`, verificando sia il percorso
  felice sia la propagazione di un errore del processo esterno.
- **Gap noto**: `backup:databases` non ha ancora test, perché
  `MySql::create()->dumpToFile()` invoca `mysqldump` realmente. Per
  renderlo testabile senza un MySQL reale, servirebbe iniettare/mockare il
  dumper (es. wrappare `MySql::create()` dietro un binding risolvibile nel
  container, o un'interfaccia `DumpsDatabase`). Non fatto in questa
  passata per non introdurre un'astrazione prematura senza un secondo
  caso d'uso reale — ma è il primo punto da affrontare se si aggiungono
  nuove feature a `BackupDatabases`.
- Ogni nuovo comando/feature di backup **deve** avere test che usano
  `Process::fake()` per gli step esterni (`aws`, `gzip`, ecc.) — non deve
  mai colpire R2 o eseguire binari reali nella test suite.

## Convenzioni per estensioni future ("vibe coding")

Quando si aggiunge una funzionalità a questo progetto:

1. **Nuove variabili d'ambiente** → sempre in `config/backup_paths.php` +
   `.env.example`, mai `env()` sparso nei Command.
2. **Nuovi comandi di backup/sync** → seguire lo schema esistente: ritorno
   `self::SUCCESS`/`self::FAILURE` accurato, `Process::env()` per le
   credenziali (mai in argv), `->timeout()` esplicito per operazioni di
   rete, log/errori parlanti in italiano come il resto della codebase.
3. **Modifiche alla retention** → passano da
   `CleanBackupsRetentionTest.php` prima del deploy.
4. **Nuove destinazioni di storage oltre R2** → non aggiungere un quarto
   set di `env()` sparsi: estendere la sezione `r2` di
   `config/backup_paths.php` con un pattern a più "profili" (es.
   `config('backup_paths.destinations.r2')`, `...destinations.s3_glacier`)
   piuttosto che duplicare la logica di sync nei singoli comandi.
5. **Non introdurre una UI web** senza discuterne esplicitamente: il
   progetto è nato come tool headless da cron; se serve visibilità sullo
   stato dei backup, la strada più economica è un dashboard esterno che
   legge l'heartbeat/i log, non un pannello Laravel con autenticazione da
   mantenere.
6. **`Process::env()`**, non `Process::withEnv()` — la seconda non esiste
   in questa versione del componente Process di Laravel e fallisce a
   runtime con `Call to undefined method`; è già successo in questa
   codebase su tutti e tre i comandi di sync R2.

## Backlog di miglioramenti non ancora implementati

In ordine di impatto/costo:

- Test di integrazione per `backup:databases` dietro un'interfaccia
  mockabile per il dumper (vedi "Gap noto" sopra).
- Preflight check a inizio comando: verificare che il binario `aws` (e
  `mysqldump`/`gzip`) sia presente nel `PATH` prima di partire, con errore
  esplicito invece del generico fallimento del `Process`.
- Cifratura GPG lato client dei dump prima dell'upload su R2, per i
  database con dati GDPR.
- Notifica di fallimento più ricca del solo ping `/fail` (es. email/Slack
  con l'elenco dei DB falliti), specialmente perché oggi un fallimento
  parziale (1 DB su 5 fallisce il dump, ma la sync generale riesce) produce
  comunque `self::FAILURE` aggregato ma senza dettaglio su *quale* DB è
  fallito nell'heartbeat.
- Restore documentato/testato: esiste solo la direzione backup → R2, non
  c'è un comando o una guida per il ripristino da un dump R2. Per uno
  strumento di backup è il gap più critico da colmare prima che serva
  davvero in un disaster recovery.
