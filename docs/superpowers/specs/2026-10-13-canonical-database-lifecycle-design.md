# Lifecycle canonico dei database

**Data:** 13 ottobre 2026  
**Stato:** approvato  
**Ambito:** specifica architetturale; nessun codice operativo

## 1. Contesto e incidente

L'ecosistema Unico usa più database MySQL nati in momenti diversi, con nomi che non distinguono in modo affidabile produzione, sviluppo e test. Alcune applicazioni hanno inoltre avuto accessi diretti a database di altre applicazioni e configurazioni con fallback impliciti. In particolare, la connessione a `unico-core` può ripiegare sulla connessione predefinita quando la configurazione dedicata manca: questo comportamento può indirizzare migrazioni o test verso il database sbagliato.

L'incidente di riferimento è quindi una perdita di separazione tra ambienti e ownership, con rischio di eseguire operazioni distruttive su dati canonici durante ricostruzioni, cloni o test. La specifica non riporta nomi di persone, dati personali, hostname, indirizzi, credenziali o altri segreti.

La situazione attuale dei backup riduce il rischio di perdita totale, ma non costituisce ancora un control plane di lifecycle:

- `backups3` produce dump `.sql.gz` in sequenza;
- i dump sono salvati localmente in `storage/app/backups/databases/{database}/` e sincronizzati su R2 sotto `s3://{bucket}/{destination_prefix}/databases/{database}/`;
- il nome corrente è `{database}_{Y-m-d_H-i-s}.sql.gz`;
- `destination_prefix` separa server o committenti;
- ogni file è verificato come archivio gzip, ma non esiste un manifest che leghi i sei dump a un unico run;
- non esistono checksum applicativi persistenti, firma del gruppo, bounded skew registrato o attestazione della verifica di restore;
- i dump sono sequenziali e quindi non rappresentano una transazione distribuita fra i sei domini.

## 2. Obiettivi e non-obiettivi

### 2.1 Obiettivi

Il lifecycle deve:

1. stabilire un database canonico di produzione per ciascun dominio;
2. separare fisicamente produzione, sviluppo locale e PHPUnit;
3. assegnare ownership esclusiva delle migration;
4. ricostruire in modo ripetibile i canonici a partire dai dump R2 esistenti;
5. costruire e verificare una generazione prima di promuoverla;
6. eseguire cutover e rollback coordinati dei sei domini;
7. produrre cloni locali coordinati e sanitizzati;
8. impedire per costruzione che PHPUnit o strumenti locali raggiungano la VPS;
9. rendere ogni operazione ispezionabile, idempotente, riprendibile e auditabile;
10. supportare più committenti senza credenziali o identificativi sensibili hardcoded;
11. preparare la transizione dagli accessi DB cross-app ad API versionate.

### 2.2 Non-obiettivi

Questa specifica non:

- implementa comandi, migration, proxy, container o pipeline;
- introduce un nuovo workflow engine: UnicoBPM resta l'unico orchestratore dei processi;
- sposta nel core dati solo perché usati da più applicazioni;
- usa dump reali per PHPUnit;
- rende R2 un database o una sorgente applicativa online;
- promette una transazione distribuita fra domini: il coordinamento avviene con write fence, verifiche e stato persistente.

## 3. Topologia e naming canonico

### 3.1 I sei domini

| Dominio | Canonico VPS | Clone sviluppo locale | PHPUnit effimero |
|---|---|---|---|
| Core condiviso | `unico_core` | `unico_core_test` | `unico_core_phpunit_<run>_<process>` |
| CRM prestiti | `unicoloan` | `unicoloan_test` | `unicoloan_phpunit_<run>_<process>` |
| Contabilità | `proforma` | `proforma_test` | `proforma_phpunit_<run>_<process>` |
| Compliance OAM | `unicooam` | `unicooam_test` | `unicooam_phpunit_<run>_<process>` |
| Orchestrazione BPM | `unicobpm` | `unicobpm_test` | `unicobpm_phpunit_<run>_<process>` |
| Agenti | `unicoagent` | `unicoagent_test` | `unicoagent_phpunit_<run>_<process>` |

`run_id` è un ULID canonico in minuscolo di 26 caratteri, conforme a `[0-9a-hjkmnp-tv-z]{26}`; `<run>` coincide con questo valore. `<process>` è l'indice numerico del processo PHPUnit. I nomi sono generati, mai accettati come SQL libero.

### 3.2 Le tre istanze

Esistono tre classi di istanza, con trust boundary distinti:

1. **Canonici VPS.** Istanza di produzione raggiungibile soltanto dalle applicazioni VPS e dal control plane con account dedicati. Durante build e cutover può esistere una coppia blue/green nello stesso perimetro VPS; non è un quarto ambiente e usa reti, segreti e audit di produzione.
2. **Sviluppo locale.** Istanza MySQL locale contenente esclusivamente i sei cloni `*_test`, derivati da un unico run coordinato e resi accessibili solo dopo sanitizzazione riuscita.
3. **PHPUnit locale isolato.** Istanza o container separato da quella di sviluppo, effimero per run, senza route di rete verso VPS/R2 e senza credenziali VPS. Contiene soltanto database `*_phpunit_<run>_<process>`.

Sviluppo e PHPUnit non condividono socket, volume dati, utenti MySQL o file di configurazione. Un nome di produzione è vietato sulle istanze locali; un nome `_test` o `_phpunit_` è vietato sulla VPS.

## 4. Ownership di schema e dati

### 4.1 Migration

- Il pacchetto `unico-core` possiede ed esegue soltanto le migration di `unico_core`.
- Ogni applicazione possiede ed esegue soltanto le migration del proprio database: `unicoloan`, `proforma`, `unicooam`, `unicobpm` o `unicoagent`.
- Una migration non può creare, alterare, rinominare o cancellare oggetti in un altro dominio.
- Le foreign key fisiche cross-database sono vietate. I riferimenti cross-domain sono identificativi applicativi con vincoli verificati via API o processi di riconciliazione.
- UnicoBPM approva e orchestra il processo; non acquisisce ownership delle tabelle operative degli altri domini.
- `backups3` esegue inspect, build, verify, cutover, rollback, clone e status. Non decide ownership, regole di business o approvazioni.

### 4.2 Transizione API-first

Ogni accesso cross-app esistente viene censito con chiamante, proprietario, tabelle/colonne lette o scritte, frequenza e requisito di consistenza. La transizione segue quest'ordine:

1. l'app proprietaria espone un'API versionata o un evento idempotente;
2. il chiamante passa in modalità shadow-read e confronta il risultato con l'accesso DB esistente;
3. le scritture passano all'API; le letture dirette diventano read-only temporanee;
4. superati i criteri di parità, l'account cross-app perde i privilegi;
5. l'accesso diretto viene rimosso e il controllo automatico impedisce la regressione.

Le API espongono il minimo dato necessario, applicano autorizzazione per committente e propagano correlation ID senza PII nei log.

## 5. Control plane backups3 e R2

Cloudflare R2 è lo storage immutabile dei payload e delle attestazioni. `backups3` è il control plane che seleziona artefatti, valida identità e policy, materializza database e registra lo stato. UnicoBPM richiede e approva i run tramite API/eventi idempotenti; l'assenza di BPM non autorizza un'esecuzione distruttiva.

Il path v1 è:

```text
s3://{bucket}/{tenant_prefix}/database-lifecycle/v1/runs/{run_id}/manifest.json
s3://{bucket}/{tenant_prefix}/database-lifecycle/v1/runs/{run_id}/manifest.sig
s3://{bucket}/{tenant_prefix}/database-lifecycle/v1/runs/{run_id}/dumps/{domain}.sql.gz
s3://{bucket}/{tenant_prefix}/database-lifecycle/v1/runs/{run_id}/reports/{phase}.json
```

Il layout storico resta leggibile in sola lettura:

```text
s3://{bucket}/{destination_prefix}/databases/{physical_name}/{physical_name}_{timestamp}.sql.gz
```

Nessun oggetto storico viene rinominato o sovrascritto. L'import iniziale genera un manifest v1 che referenzia gli object key storici.

## 6. Selezione del gruppo storico e bounded skew

Per ricostruire dai dump sequenziali esistenti, `inspect`:

1. considera soltanto dump gzip validi dei database sorgente necessari;
2. estrae il timestamp dal nome e lo confronta con metadata R2 e intestazione del dump; una discordanza superiore a 60 secondi invalida il file;
3. forma tutte le tuple complete dei sei domini in una finestra mobile di 24 ore;
4. sceglie la tupla con skew minimo, dove `skew = max(timestamp) - min(timestamp)`;
5. in caso di parità sceglie la tupla con timestamp massimo più recente;
6. accetta soltanto `skew <= 15 minuti`;
7. registra per ogni dominio timestamp, object key, size, checksum e skew complessivo.

Se non esiste una tupla completa entro 15 minuti, il run termina in `FAILED_INSPECT`: non è consentito proseguire con un gruppo parziale o allargare il limite. Si produce prima un nuovo gruppo di backup.

Lo skew descrive la non simultaneità ma non garantisce consistenza cross-domain. Le verifiche includono quindi invarianti di riconciliazione; un'invariante fallita blocca la promozione.

## 7. Manifest v1, checksum e firma

Il manifest è JSON canonico UTF-8, con chiavi ordinate e timestamp UTC RFC 3339. Contiene almeno:

- `manifest_version: 1`;
- `run_id`, `created_at`, `purpose` e stato;
- identificativo non sensibile del committente e `tenant_prefix`;
- ambiente sorgente e destinazione;
- identificatore atteso del server/cluster MySQL, mai hostname o credenziale;
- versione di `backups3`, commit delle sei applicazioni e versione migration attesa;
- per ciascun dominio: nome logico, nome fisico sorgente, object key, byte, SHA-256 del file compresso, SHA-256 del contenuto SQL decompresso, timestamp dump e metadata di compatibilità MySQL;
- algoritmo di selezione e `bounded_skew_seconds`;
- policy di sanitizzazione quando lo scopo è `dev-clone`;
- esiti delle verifiche, approvazioni e correlation ID;
- manifest padre per resume, clone, rollback o snapshot.

Ogni dump viene verificato in streaming prima e dopo il download. Il manifest è firmato con Ed25519; la chiave privata risiede in un secret store o file montato fuori dal repository, la chiave pubblica è distribuita separatamente e ruotabile tramite key ID. Una firma mancante, sconosciuta o non valida blocca build, clone e cutover. I report di fase sono anch'essi checksummed e collegati al manifest.

## 8. Configurazione multi-committente

La configurazione è una mappa per tenant caricata da config Laravel e variabili d'ambiente/secret store. Per ogni tenant definisce:

- ID tecnico e prefisso R2;
- endpoint logici blue/green e server UUID ammessi;
- mapping dei sei domini;
- riferimenti ai secret, non i valori;
- policy di retention, sanitizzazione e approvazione;
- endpoint BPM e chiave tramite secret reference;
- allowlist di rete e account MySQL.

Repository, manifest, output CLI e log non contengono password, token, endpoint con credenziali, PII o private key. Non esiste un tenant implicito: `--tenant` è obbligatorio per ogni comando di lifecycle. Una configurazione incompleta fallisce chiusa.

## 9. Suite comandi approvata

I comandi pubblici sono:

- `db:lifecycle:inspect`
- `db:lifecycle:build`
- `db:lifecycle:verify`
- `db:lifecycle:cutover`
- `db:lifecycle:rollback`
- `db:lifecycle:clone`
- `db:lifecycle:status`

`db:lifecycle:snapshot` è riservato a una fase futura e non viene implementato nel primo rilascio.

Ogni comando richiede `--tenant` e `--run-id`, salvo `status`, che può elencare i run del tenant. Tutti i comandi, eccetto `status`, sono dry-run per default: descrivono azioni, target, lock, rischi e approvazioni senza modificare database, journal o R2. L'esecuzione e la persistenza dell'esito richiedono contemporaneamente:

```text
--execute --confirm-run-id={run_id}
```

Il valore di conferma deve coincidere byte per byte con il run. `inspect --execute` e `verify --execute` restano read-only sui database: possono soltanto acquisire lock di lettura e scrivere manifest, report, checkpoint e transizioni nel control plane. `status` è sempre read-only, non accetta `--execute` e non richiede `--run-id` quando elenca i run. Nessun prompt interattivo sostituisce i flag in automazione. `--force`, target SQL arbitrari e override delle guardie non fanno parte dell'interfaccia.

## 10. State machine

Lo stato è persistito in un journal append-only locale e replicato su R2. Le transizioni ammesse sono:

```text
PLANNED
  -> INSPECTING -> INSPECTED
  -> FAILED_INSPECT

INSPECTED
  -> BUILDING -> BUILT
  -> FAILED_BUILD

BUILT
  -> VERIFYING -> VERIFIED
  -> FAILED_VERIFY

VERIFIED
  -> CUTOVER_PENDING -> CUTTING_OVER -> ACTIVE
  -> FAILED_CUTOVER

ACTIVE
  -> ROLLBACK_PENDING -> ROLLING_BACK -> ROLLED_BACK
  -> RETIRED

INSPECTED
  -> CLONING -> SANITIZING -> CLONE_VERIFIED -> CLONE_READY
  -> FAILED_CLONE
```

Gli stati `FAILED_*` possono transitare soltanto allo stato operativo precedente tramite `resume` sullo stesso run, dopo la rimozione della causa. `ROLLED_BACK` e `RETIRED` sono terminali. Un run nuovo richiede un nuovo `run_id`; non si riscrive la storia di un run terminale.

Ogni fase salva checkpoint per dominio, input hash, output hash, fencing token, tentativo, orari e classificazione dell'errore. Il resume:

- ricalcola e confronta gli hash;
- salta soltanto step già completati e verificati;
- ripete in sicurezza upload/download e verifiche;
- non ripete una promozione se il routing attivo corrisponde già all'esito registrato;
- si arresta se rileva mutazioni esterne.

## 11. Lock, concorrenza ed error handling

Esiste un lock esclusivo per `{tenant, environment}`. Cutover e rollback acquisiscono inoltre un lock coordinato sui sei domini. Il lock ha lease rinnovabile e fencing token monotono; un worker con token scaduto non può scrivere stato o eseguire SQL. Un lock non viene sottratto automaticamente: lo sblocco amministrativo richiede verifica di assenza processo, doppia approvazione e audit.

Gli errori sono classificati:

- **validation:** manifest, firma, checksum, mapping, naming o guardia non validi; nessun retry;
- **transient:** timeout o indisponibilità temporanea; retry esponenziale limitato a 3 tentativi;
- **data:** restore, migration o invariante fallita; quarantena della generazione;
- **approval:** approvazioni assenti/scadute; nessuna mutazione;
- **partial cutover:** routing non uniforme; write fence resta attivo e parte il rollback coordinato;
- **security:** mismatch host/server UUID, credenziale o rete; arresto immediato e alert.

Il cleanup non cancella gli artefatti necessari alla diagnosi o al rollback. Le generazioni fallite sono marcate e isolate.

## 12. Build, migration e verifiche

La build usa esclusivamente nomi:

```text
unico_core_build_<run-id>
unicoloan_build_<run-id>
proforma_build_<run-id>
unicooam_build_<run-id>
unicobpm_build_<run-id>
unicoagent_build_<run-id>
```

Il `run-id` usato nei nomi è una forma SQL-safe derivata dall'ID pubblico e conforme a `[a-z0-9_]+`. La build avviene sul nodo green isolato nel perimetro VPS:

1. verifica manifest, firma, checksum, server UUID, versione MySQL e spazio;
2. crea i sei database build vuoti con charset e collation dichiarati;
3. ripristina i dump senza `DEFINER`, account, grant, event scheduler o riferimenti a endpoint non approvati;
4. applica le migration del solo proprietario, in ordine deterministico e con commit applicativo registrato;
5. esegue controlli strutturali, row count, checksum per tabelle critiche, foreign reference applicative e invarianti cross-domain;
6. esegue smoke test read-only delle API;
7. promuove internamente il contenuto verificato in sei database canonici omonimi sul nodo green e ripete i checksum;
8. marca `VERIFIED` soltanto se build e canonici green sono equivalenti.

Una migration non reversibile è ammessa solo se il rollback avviene per switch alla generazione blue e se il manifest la dichiara. Nessuna migration viene eseguita direttamente sul blue attivo.

## 13. Cutover blue-green e rollback

Il cutover è unico per i sei domini:

1. acquisisce lock e approvazioni;
2. verifica che blue sia ancora la generazione attiva attesa;
3. attiva maintenance/write fence su tutte le applicazioni e drena queue e transazioni;
4. cattura posizione binlog/GTID e backup pre-cutover;
5. esegue l'ultimo delta verso green, poi ripete le invarianti;
6. attiva in un'unica operazione il bundle di connessione versionato delle sei applicazioni, passando dal nodo blue al green;
7. riavvia i pool di connessione e verifica server UUID e nomi canonici;
8. esegue smoke test coordinati;
9. rilascia il write fence soltanto dopo esito completo;
10. mantiene blue in sola lettura per 7 giorni.

Se qualsiasi controllo fallisce prima del rilascio del write fence, il routing torna a blue e il run diventa `FAILED_CUTOVER` o `ROLLED_BACK`. Dopo il rilascio, `rollback`:

1. riattiva il write fence;
2. acquisisce il delta binlog/GTID prodotto su green;
3. applica e verifica il delta su blue;
4. cambia insieme i sei riferimenti;
5. esegue smoke test e poi rilascia il fence.

Se il delta non è applicabile senza perdita, il rollback resta bloccato in sicurezza: si usa restore del backup pre-cutover più replay binlog fino al punto verificato. Non è consentito tornare a blue perdendo scritture.

## 14. Clone coordinato di sviluppo e sanitizzazione

`clone` usa un solo manifest verificato per tutti i domini. Scarica su rete controllata, ripristina in staging locale non raggiungibile dagli sviluppatori, sanitizza, verifica e infine pubblica i sei `*_test` insieme.

La policy obbligatoria:

- sostituisce ogni password applicativa con l'hash della password letterale `password`, esclusivamente nel clone;
- elimina o ruota token, API key, sessioni, cookie persistenti, secret, private key, OTP seed e reset token;
- pseudonimizza in modo deterministico nomi e identificativi che devono mantenere join, usando una chiave locale effimera non esportata;
- sostituisce email con domini `.invalid`, telefoni e indirizzi con valori sintetici non instradabili;
- riduce allegati e contenuti liberi a fixture innocue quando possono contenere PII;
- conserva soltanto gli identificativi tecnici necessari alla coerenza;
- disabilita mail, SMS, WhatsApp, webhook, pagamenti, upload esterni e ogni outbound;
- svuota queue e job pianificati, disabilita scheduler ed event scheduler MySQL;
- sostituisce endpoint esterni con stub locali;
- verifica con scanner di pattern che non restino secret o PII ad alto rischio.

Un clone fallito resta nello staging e non è accessibile. La password `password` è vietata in produzione e PHPUnit può usare credenziali sintetiche diverse.

## 15. PHPUnit

PHPUnit crea i database effimeri da migration/schema del repository e fixture/factory interamente sintetiche. Non scarica, monta o legge dump R2 o cloni reali.

Per ogni run e processo:

- il container MySQL ha rete `none` o rete interna senza route esterna;
- l'applicazione non riceve variabili o file contenenti credenziali VPS/R2;
- un utente MySQL limitato può creare e cancellare soltanto nomi conformi a `^(unico_core|unicoloan|proforma|unicooam|unicobpm|unicoagent)_phpunit_[a-z0-9_]+_[0-9]+$`;
- teardown elimina soltanto i database creati dal medesimo fencing token;
- il container e il volume vengono distrutti a fine run;
- outbound, queue esterne e scheduler restano disabilitati.

## 16. Guardie multilivello

Le guardie sono cumulative:

1. **Rete:** allowlist VPS per control plane; deny-by-default locale; PHPUnit senza route VPS/R2.
2. **Credenziali:** account distinti per backup read-only, build, migration proprietaria, cutover e clone. Nessun account applicativo possiede `DROP DATABASE`.
3. **Identità host:** confronto fra environment atteso, server UUID MySQL, endpoint ID e tenant. Un mismatch blocca prima del SQL.
4. **Naming:** regex separate per canonico, build, clone e PHPUnit; nomi quotati da generatori interni, mai da input SQL.
5. **Manifest:** firma, checksum, tenant, scopo e parent run obbligatori.
6. **SQL policy:** blocco di `DROP DATABASE`, `DROP TABLE`, `TRUNCATE`, `RENAME`, grant e comandi distruttivi fuori da fase, allowlist e fencing token. Le migration non possono qualificare un altro database.
7. **Applicazione:** `APP_ENV`, modalità maintenance e marker di istanza coerenti con l'operazione.
8. **Conferma:** dry-run default, `--execute`, conferma esatta del run e doppia approvazione quando prevista.

### 16.1 Fix prioritario unico-core

Prima di qualsiasi rollout, la connessione `unico-core` deve essere fail-closed:

- host, porta, database, username e password dedicati sono obbligatori nell'ambiente che usa il core;
- l'assenza di una chiave non può ripiegare su `DB_*`, sulla connessione applicativa predefinita, su SQLite o su un valore convenzionale;
- bootstrap, comandi migration e test verificano nome database, host/server UUID ed environment;
- su VPS è ammesso soltanto `unico_core`; in sviluppo soltanto `unico_core_test`; in PHPUnit soltanto il pattern effimero;
- il controllo avviene prima di aprire una transazione o eseguire migration.

Questo fix è P0 e precede inspect, build e clone.

## 17. Ricostruzione iniziale e mapping fisico

Il mapping approvato delle sorgenti correnti verso i canonici è:

| Sorgente fisica corrente | Canonico | Trattamento |
|---|---|---|
| `unico_core_test` | `unico_core` | sorgente primaria del core; il suffisso storico non determina l'ambiente |
| `unicoloan_core` | `unicoloan` | sorgente primaria app-owned |
| `proforma_core` | `proforma` | sorgente primaria app-owned |
| `unicooam_core` | `unicooam` | sorgente primaria app-owned |
| `unicobpm_dev` | `unicobpm` | sorgente primaria app-owned |
| `unicoagent` | `unicoagent` | sorgente primaria già omonima |

I database storici `unicoloan`, `proforma`, `unicooam` e `unicobpm` sono sorgenti legacy supplementari, non canonici affidabili per il solo nome. Durante `inspect` le loro tabelle vengono classificate per ownership:

- una tabella assente dalla sorgente primaria ma appartenente al dominio viene importata;
- una tabella duplicata viene confrontata per schema, chiavi e recenza; una divergenza produce un report e blocca la build;
- una tabella appartenente a un altro dominio viene instradata al proprietario;
- una tabella senza proprietario esplicito viene messa in quarantena e blocca il cutover;
- viste invalide vengono ricostruite dal proprietario o escluse con decisione firmata nel manifest; non sono promosse implicitamente.

La ricostruzione non sovrascrive alcun database corrente. Produce i `*_build_<run-id>`, applica migration, verifica, crea i canonici green e usa il cutover coordinato. I nomi legacy restano read-only fino alla scadenza del rollback e poi vengono rimossi soltanto tramite run distruttivo approvato.

## 18. Backup, PITR, retention, audit e approvazioni

Lo steady state prevede:

- dump logico completo giornaliero dei sei domini con manifest firmato;
- binlog/GTID caricati su R2 almeno ogni 5 minuti;
- RPO massimo 5 minuti e obiettivo RTO 60 minuti;
- verifica giornaliera di checksum e firma;
- restore drill automatico settimanale in ambiente isolato;
- retention locale VPS di 7 giorni;
- su R2: 14 giornalieri, 52 settimanali e 36 mensili per dominio;
- binlog conservati 35 giorni;
- manifest, report di audit e approvazioni conservati 7 anni;
- versioning e Object Lock R2 obbligatori per la finestra di rollback; se il bucket corrente non offre Object Lock, prima della fase 6 viene creato un bucket dedicato che lo offre.

Servono due approvatori distinti per cutover, rollback dopo apertura alle scritture, cancellazione di canonici/legacy, riduzione retention, sblocco forzato e rotazione della chiave di firma. Il richiedente non può approvare entrambe le posizioni. Le approvazioni scadono dopo 4 ore e sono legate a tenant, run, manifest hash e azione.

L'audit registra attore tecnico, approvatori, comando redatto, hash, transizioni, fencing token, server UUID, esiti e tempi. Non registra credenziali, SQL con dati, PII o contenuti dei dump.

## 19. Blacklist, history e ownership dati

La richiesta blacklist/history non modifica l'ownership:

- la blacklist clienti/fornitori appartiene a `unicooam`, perché è dato di compliance specifico dell'applicazione;
- la cronologia operativa e gli audit restano nel dominio che genera e governa l'evento;
- `unico_core` contiene soltanto anagrafiche realmente condivise e i relativi identificativi, non copie di blacklist o history per comodità;
- UnicoBPM conserva stato di processo, RACI ed esecuzioni, ma interroga blacklist e history tramite API del proprietario;
- una vista aggregata può essere costruita da BPM o da un servizio di lettura usando API/eventi, senza trasferire ownership e senza scritture dirette cross-app;
- retention, rettifica e autorizzazione sono applicate dal proprietario del dato.

Il lifecycle deve preservare gli identificativi necessari alle correlazioni e verificare che l'API-first restituisca gli stessi esiti autorizzati prima di rimuovere gli accessi legacy.

## 20. Rollout per fasi

### Fase 0 — Guardie e inventario

**Attività:** fix fail-closed `unico-core`, censimento connessioni, server UUID, tabelle e accessi cross-app.  
**Accettazione:** nessun fallback; tutti i database e le tabelle hanno owner; test delle guardie verdi.  
**Rollback:** ripristino della sola configurazione applicativa precedente senza eseguire lifecycle; il rollout resta bloccato finché il fix non è nuovamente valido.

### Fase 1 — Control plane read-only

**Attività:** `inspect`, `status`, manifest v1, checksum, firma e import del layout storico.  
**Accettazione:** gruppo completo con skew massimo 15 minuti, firma valida e mapping senza tabelle orfane.  
**Rollback:** eliminazione dei soli metadata non promossi; dump storici immutati.

### Fase 2 — Build e verify isolati

**Attività:** costruzione `*_build_<run-id>`, migration proprietarie e suite verifiche.  
**Accettazione:** sei domini `VERIFIED`, invarianti e smoke test verdi, equivalenza build/canonici green.  
**Rollback:** distruzione della sola generazione green/build tramite run approvato; blue invariato.

### Fase 3 — Clone sviluppo

**Attività:** clone coordinato e sanitizzazione.  
**Accettazione:** scanner PII/secret verde, outbound e scheduler bloccati, sei `*_test` pubblicati insieme.  
**Rollback:** revoca accesso e distruzione del clone; nessun effetto VPS.

### Fase 4 — Cutover iniziale

**Attività:** backup pre-cutover, write fence, delta, switch blue-green e smoke test.  
**Accettazione:** tutte le applicazioni connesse al green atteso, canonici corretti, queue sane e invarianti verdi.  
**Rollback:** prima del rilascio scritture, switch immediato a blue; dopo il rilascio, replay delta verificato come definito nella sezione 13.

### Fase 5 — API-first

**Attività:** API/eventi proprietari, shadow-read, revoca grant cross-app.  
**Accettazione:** parità misurata per 14 giorni, zero accessi DB cross-app nei log, contratti API monitorati.  
**Rollback:** riabilitazione temporanea e read-only del singolo accesso censito, con scadenza massima 72 ore e doppia approvazione; le scritture restano via API.

### Fase 6 — Steady state e PITR

**Attività:** backup manifestati, binlog, restore drill, retention e audit.  
**Accettazione:** due restore drill settimanali consecutivi entro RPO/RTO e alert verificati.  
**Rollback:** mantenimento del precedente backup giornaliero senza ridurre retention; nessuna cancellazione finché il nuovo regime non è stabile.

## 21. Acceptance criteria complessivi

Il progetto è accettato quando:

1. i sei canonici VPS hanno esattamente i nomi approvati;
2. sviluppo usa soltanto i sei `*_test` sanitizzati;
3. PHPUnit usa soltanto database effimeri sintetici su istanza senza rete VPS;
4. nessuna migration modifica un dominio non posseduto;
5. nessun accesso cross-app in scrittura resta diretto;
6. ogni run ha manifest v1 firmato, checksum, bounded skew e audit;
7. build, cutover e rollback sono idempotenti e riprendibili;
8. un cutover parziale non apre le scritture;
9. il fallback `unico-core` è impossibile e coperto da test;
10. backup e PITR rispettano RPO, RTO e retention;
11. blacklist e history restano presso i proprietari;
12. nessun segreto o PII appare in repository, manifest, log o report.

## 22. Strategia di test sicura

La verifica dell'implementazione seguirà questa piramide:

- unit test per parsing nomi, selezione tuple, skew, canonical JSON, firma, state machine, regex e redazione;
- feature test con R2 e BPM fake per dry-run, approvazioni, resume, lock e idempotenza;
- integration test su MySQL container usa-e-getta con soli schema e fixture sintetiche;
- fault injection per timeout, checksum errato, firma errata, lock scaduto, disco pieno, migration fallita e cutover parziale;
- test di rete che dimostrano l'assenza di route e credenziali VPS in PHPUnit;
- restore drill su copie sintetiche e, solo nel perimetro autorizzato, su dump reali sanitizzati prima dell'accesso;
- prova blue-green con write fence e replay binlog prima della produzione;
- test negativi obbligatori per ogni destructive guard.

I test non eseguono comandi distruttivi su nomi canonici, non usano endpoint di produzione e non inviano outbound reale.

## 23. Decisioni approvate e default prudenti

Sono decisioni definitive:

- sei domini e naming descritti nella sezione 3;
- tre trust boundary separati;
- migration possedute esclusivamente dal dominio;
- BPM approva/orchestra e `backups3` esegue;
- R2 conserva payload e attestazioni;
- manifest v1 firmato Ed25519 e checksum SHA-256;
- gruppo storico a skew minimo con limite rigido di 15 minuti;
- dry-run default e conferma del run per le mutazioni;
- build in `*_build_<run-id>`;
- cutover coordinato blue-green con write fence;
- clone reale soltanto dopo sanitizzazione;
- PHPUnit sempre sintetico;
- fallback `unico-core` rimosso prima di ogni altra fase;
- API-first per ogni accesso cross-app;
- blacklist in `unicooam` e history presso il produttore;
- retention, RPO/RTO, doppia approvazione e audit come definiti.

Le assunzioni residue sono risolte con questi default:

- se identità, ownership, firma, checksum, skew o approvazione non sono dimostrabili, l'operazione si arresta;
- se un dato sembra condiviso ma non ha ownership approvata, resta in quarantena e non entra in `unico_core`;
- se una fase non può garantire zero perdita, mantiene il write fence e non effettua switch;
- se una configurazione tenant manca, non si usa alcun tenant predefinito;
- se un retry non è dimostrabilmente idempotente, richiede resume dal checkpoint verificato;
- se una policy locale è meno restrittiva di questa specifica, prevale questa specifica.

Non restano decisioni aperte necessarie all'implementazione iniziale.
