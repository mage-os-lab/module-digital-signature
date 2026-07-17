# Analisi - Modulo MageOS_DigitalSignature

> Documento di analisi funzionale/tecnica (in evoluzione). Stato: bozza iniziale.

## 1. Obiettivo

Modulo per Mage-OS / Magento 2.4.x (compatibile con tema Hyva e Hyva Checkout) che consente al
cliente di richiedere, in fase di carrello/minicart/checkout, l'invio di uno o più documenti
PDF (contratti) da firmare digitalmente. I documenti vengono predisposti a partire da template
PDF con placeholder, popolati con i dati reali del cliente/ordine, e inviati a un provider di
firma digitale esterno tramite connettori pluggable.

## 2. Glossario

- **Template documento**: PDF predisposto dal merchant con tag placeholder, definito tramite
  area admin dedicata.
- **Placeholder/tag**: stringa nel PDF nel formato `{WSIGN#80,20#email@esempio.com}`, dove:
  - `WSIGN` = identificatore del provider/tipo di tag
  - `80,20` = dimensioni (larghezza,altezza in mm) dell'area firma
  - `email@esempio.com` = email del firmatario (sostituita con dati reali al momento della
    generazione)
- **Documento firma**: istanza generata per un ordine/riga ordine a partire da un template,
  con stato e riferimenti al processo di firma presso il provider.
- **Provider di firma**: servizio esterno (es. WsSign, DocuSign) che gestisce il processo di
  firma digitale del documento.
- **Trigger**: evento che avvia la generazione/invio di un documento firma.

## 3. Scope dei template

Ogni template documento ha uno **scope**:

- **Carrello/ordine**: un solo documento generato per l'intero ordine.
- **Prodotto**: un documento generato per ciascuna riga ordine (order item) a cui è associato
  un template di questo tipo.

Un ordine può quindi produrre **più documenti firma**: al massimo uno di scope "carrello" (se
esiste un template attivo con questo scope) più N documenti di scope "prodotto" (uno per ogni
riga ordine con template assegnato).

### Assegnazione template ↔ prodotto

- Dalla scheda del template: griglia di assegnazione prodotti (analoga all'assegnazione
  prodotti↔categorie dalla scheda categoria).
- Dalla scheda prodotto: tab dedicato per assegnare/gestire il template.

## 4. Trigger di generazione

Trigger disponibili per ciascun template:

1. **Conferma ordine**
2. **Creazione fattura**
3. **Fattura pagata**
4. **Manuale da backend**

### Configurazione a due livelli

- **Globale** (Stores > Configuration): trigger di default applicato ai template.
- **A livello di prodotto**: può sovrascrivere il trigger globale per quel prodotto/template.
  L'override ha senso anche quando coesistono un documento di scope "carrello" e uno di scope
  "prodotto" sullo stesso ordine: ciascuno può seguire un trigger differente (es. documento
  di carrello alla conferma ordine, documento del prodotto specifico alla fattura pagata).

### Mappatura trigger → eventi Magento

| Trigger | Evento/observer |
|---|---|
| Conferma ordine | Observer/plugin sul place dell'ordine (con controllo anti-doppia-esecuzione) |
| Creazione fattura | Observer su `sales_order_invoice_save_after` |
| Fattura pagata | Observer su `sales_order_invoice_pay` (copre capture online e fatture offline/"Capture Offline"/segnate pagate manualmente); eventuale fallback su `sales_order_invoice_save_after` con controllo transizione a stato PAID se in test emergessero casi scoperti |
| Manuale da backend | Azione/bottone nella vista ordine, anche in mass action dalla griglia ordini |

### Anti-doppia-esecuzione

Prima di generare un documento per la combinazione (order_item_id, template_id, trigger_code),
il servizio verifica se esiste già un record (anche in storico) per quella combinazione; se sì,
l'operazione viene saltata.

**Garanzia atomica (2026-06-12)**: oltre al check applicativo, la tabella documento ha un
**indice univoco** su (order_id, order_item_id, template_id, trigger_code, is_active), dove
`is_active` vale 1 per il documento attivo e NULL per quelli storicizzati/annullati (i NULL
non partecipano all'unicità in MySQL): due processi concorrenti non possono creare due
documenti attivi per la stessa combinazione. Per lo scope carrello `order_item_id` vale 0
(non NULL), così l'indice resta efficace.

## 5. Flusso operativo

1. Il cliente seleziona, in cart/minicart/checkout (anche Hyva), la richiesta di firma
   digitale (modalità esatta da definire in base allo scope dei template attivi).
2. Al verificarsi del trigger configurato (per ciascun documento), il modulo:
   - recupera il template PDF (per store view/lingua dell'ordine),
   - sostituisce i tag placeholder con i dati reali (es. email del firmatario, dimensioni area
     firma già definite nel tag),
   - invia il PDF al connettore del provider di firma configurato.
3. Il provider avvia il processo di firma e gestisce posizionamento/UX della firma stessa
   (il modulo non disegna riquadri/QR).
4. Il provider notifica via **callback** gli aggiornamenti di stato, che vengono mappati sugli
   stati interni del documento.
5. Tutte le operazioni pesanti (generazione PDF, chiamata al provider, elaborazione callback)
   sono gestite in modo **asincrono**: l'azione immediata (automatica o manuale) produce solo
   un messaggio "richiesta presa in carico". L'utente viene informato in caso di completamento
   o di errore definitivo.
   - **Infrastruttura (2026-06-12)**: message queue di Magento con connection **`db`**
     (consumer avviati da cron) — stessa API delle code AMQP ma senza richiedere RabbitMQ,
     compatibile con hosting condivisi. Migrabile ad AMQP via sola configurazione.
6. La callback del provider viene trattata come **ping**: alla ricezione non ci si fida del
   payload, ma si accoda un job che interroga l'API del provider (polling stato documento) e
   aggiorna lo stato interno (vedi `docs/wssign-api.md`: il body della callback WsSign può
   essere vuoto).
7. **Riconciliazione**: un cron periodico interroga lo stato presso il provider per tutti i
   documenti in stato non finale (es. `sent`), per riallineare gli stati in caso di callback
   perse (downtime, deploy, problemi di rete).

## 6. Architettura connettori provider

Pattern pluggable, analogo ai metodi di pagamento:

- Interfaccia comune per i connettori di firma (invio documento, gestione callback, mapping
  stati).
- Implementazioni specifiche per provider (WsSign, DocuSign, futuri), ciascuna con propria
  configurazione in `system.xml` (endpoint, credenziali/bearer token, ecc.).
- Elenco provider attivabili/configurabili da admin.

### Provider iniziali

- **WsSign**: API documentata in `docs/wssign-api.md` (estratta dal progetto
  `iscrizioni.sthdev04.myvdc.it`; non esiste documentazione ufficiale online). Nota: con
  `signatureType OTP_SMS` il **telefono del firmatario è obbligatorio**.
- **Dummy** (2026-06-12): connettore di test integrato nel modulo che simula upload/avvio
  firma/callback in locale, senza servizi esterni. Serve per collaudare l'intero flusso
  end-to-end senza credenziali e fa da implementazione di riferimento dell'interfaccia
  connettore. Attivabile solo se esplicitamente selezionato in configurazione.
- **DocuSign**: nessuna documentazione disponibile al momento — fuori scope per la beta.

## 7. Modello dati

### 7.1 Template documento

Entità admin (gestita tramite griglia in stile classico + form di edit) con:

- file PDF con placeholder, **per store view/lingua** (template sempre multilingua)
- **scope**: "carrello/ordine" oppure "prodotto"
- assegnazione prodotti (se scope = prodotto)
- **trigger di generazione** (con possibilità di override a livello prodotto)
- **firma obbligatoria** (`is_required`): se attivo, il documento si genera sempre,
  indipendentemente dalla scelta del cliente al checkout (vedi §13-bis)

### 7.2 Documento firma

Tabella custom (nome provvisorio `[vendor]_[modulo]_document`, vendor/modulo del modulo non
ancora definitivi) con i seguenti campi indicativi:

| Campo | Descrizione |
|---|---|
| `entity_id` | PK |
| `order_id` | FK a `sales_order` |
| `order_item_id` | FK a `sales_order_item` (0 se scope "carrello") |
| `template_id` | FK al template documento usato |
| `provider_code` | connettore di firma usato (wssign, dummy, ...) |
| `provider_process_id` | riferimento esterno del processo di firma |
| `status` | stato interno (vedi §8) |
| `is_active` | 1 = documento attivo, NULL = storicizzato/annullato (per indice univoco §4) |
| `trigger_code` | trigger che ha generato il documento |
| `pdf_path` | path del PDF generato (con tag sostituiti) |
| `signed_pdf_path` | path del PDF firmato (quando disponibile) |
| `signer_email` | email effettiva del firmatario |
| `signer_phone` | telefono del firmatario (richiesto da provider con OTP SMS) |
| `callback_token` | token segreto random per validare la callback del provider |
| `retry_count` | numero di tentativi effettuati |
| `error_message` | eventuale messaggio di errore |
| `created_at` / `updated_at` | timestamp |

**Granularità**: un documento per **riga ordine** (order item), non per unità di quantità.

**Cronologia**: lo storico viene sempre mantenuto. In caso di rigenerazione/reinvio, se il
provider supporta la sostituzione/cancellazione del documento in firma, il documento "attivo"
precedente viene annullato/cancellato lato provider e si crea un nuovo record, mantenendo i
precedenti come storico (eventualmente tramite una tabella di log/eventi associata).

## 8. Stati del documento e mapping provider

Stati interni previsti: `pending`, `generated`, `sent`, `signed`, `declined`, `expired`,
`error`.

È prevista una configurazione admin che mappa gli stati restituiti da ciascun provider (via
callback) sugli stati interni. Per ciascuno stato interno è inoltre configurabile se è
consentita la **rigenerazione** del documento (manuale e/o automatica via trigger).

## 9. Area amministrativa

- **Griglia template documenti** (stile classico): elenco, creazione/edit template, scope,
  trigger, assegnazione prodotti, file per store view. UI di dettaglio del form da definire in
  una fase successiva.
- **Tab "Documenti firma"** nella vista ordine: elenco documenti (storico incluso) con stato,
  link al PDF generato/firmato, riferimento al processo provider.
  - Bottone "Genera e invia documento" per righe senza documento attivo.
  - Bottone "Rigenera/Reinvia" se esiste già un documento attivo (soggetto alla regola di
    stato di §8).
- **Mass action** nella griglia ordini: "Genera documenti firma" per elaborazione in blocco.
- **Configurazione** (Stores > Configuration): provider attivi e relative credenziali, trigger
  di default, mapping stati provider → stati interni e regole di rigenerazione.

## 10. ACL

Risorse ACL separate sotto `MageOS_DigitalSignature::digitalsignature` (root menu):

- `::template` - gestione template documenti
- `::document` - visualizzazione/gestione documenti firma, azioni rigenera/reinvia
- `::config` - configurazione provider, trigger di default, mapping stati

Questo consente, ad esempio, di dare a ruoli come il customer service accesso solo alla
gestione documenti, senza visibilità sulla configurazione provider.

## 11. Notifiche

- **Documento pronto per la firma**: email al cliente, **opzionale/disattivabile** da
  configurazione (il provider spesso invia già un proprio invito a firmare).
- **Documento firmato**: nessun allegato PDF via email; solo **link nell'area "i miei ordini"**
  del cliente per scaricare il documento firmato.
- **Errore nel processo**: notifica all'admin per intervento manuale.
- **Documento scaduto/rifiutato**: notifica admin (eventuale notifica cliente da valutare).

## 12. Conservazione documenti

- **Storage**: filesystem locale, con un'interfaccia di storage pluggable che consenta in
  futuro l'integrazione di servizi esterni (es. S3).
- **Directory protetta (2026-06-12)**: i PDF **generati e firmati** vengono salvati sotto
  `var/digitalsignature/` (non servita dal web server): il download avviene **esclusivamente via
  controller** con verifica di autorizzazione — il cliente accede solo ai documenti dei propri
  ordini (area "i miei ordini"), l'admin tramite ACL `::document`. I PDF **template** caricati
  dal merchant restano per ora in `media/digitalsignature/templates/` (servono all'anteprima
  admin); la loro protezione via controller dedicato è prevista in fase 2.
- **Retention**: configurabile da backend (durata di conservazione per motivi fiscali/legali),
  valori esatti da definire.

**Implementato (2026-07-03)**: gruppo config `digital_signature/retention/*` (`enabled` Yesno
default 0 — opt-in esplicito, operazione distruttiva; `retention_days` default 3650 = 10 anni
indicativo, da verificare coi termini fiscali/legali reali del merchant; `batch_limit` default
200, stesso pattern throttling di `reconcile_batch_limit`). Cron giornaliero
`digitalsignature_retention_cleanup` (`0 3 * * *` → `Cron\RetentionCleanup::execute`), disattivato
di default: seleziona i documenti in **stato finale** (`Status::FINAL_STATES` = signed/
declined/expired/canceled — `error` escluso, resta soggetto a retry) con `updated_at` oltre
`retention_days` e non ancora purgati (`purged_at IS NULL`). Per ciascuno: elimina i file PDF
generato/firmato dallo storage (`DocumentStorageInterface::delete()`, nuovo metodo,
idempotente), azzera `pdf_path`/`signed_pdf_path`/`signer_email`/`signer_phone` sul record e
imposta `purged_at`; azzera anche i `payload` grezzi nello storico eventi
(`document_log`, spesso contengono PII da callback/API) via
`DocumentRepositoryInterface::purgeLogPayloads()`. **Il record e lo storico stato/evento
restano sempre** (id, order_id, status, date, messaggi) per audit fiscale — solo PDF e PII
vengono cancellati. Nessun test unit per `RetentionCleanup` stesso: come `Cron\Reconcile`
(nessun precedente in repo), dipende da `CollectionFactory` generata da Magento a compile-time,
non mockabile nell'harness PHPUnit standalone senza framework reale — verifica rimandata a test
dinamico in env quando necessario.

**Bug critico trovato e corretto (2026-07-03), scoperto dal test E2E di upload PDF malevolo**:
`Controller\Adminhtml\Template\Upload::validateUploadedPdf()` ricostruiva il path assoluto del
file temporaneo leggendo `$uploadResult['path']`, ma `ImageUploader::saveFileToTmpDir()` del
core Magento fa `unset($result['path'])` prima di restituire il risultato — quella chiave non
esiste mai. Di conseguenza `TemplateValidator::validate()` (magic bytes, xref classico, scan
tag, difesa decompression-bomb) **non veniva mai eseguito**: ogni upload, anche di PDF
validi, falliva con "il contenuto dal file ... non può essere letto", rendendo la funzione di
upload template **completamente inutilizzabile** in produzione (non solo per attacchi). Fix:
il path va ricostruito dalla stessa `baseTmpPath` (`digitalsignature/tmp`, la stessa configurata
nel virtualType `PdfUploader` in `di.xml`) via `Filesystem::getDirectoryRead(MEDIA)
->getAbsolutePath()`, iniettando `Magento\Framework\Filesystem` nel controller. Verificato
live in env (Object Manager + reflection sul metodo privato): PDF valido con tag → nessuna
eccezione; PDF senza tag → rifiutato correttamente con messaggio, file temporaneo ripulito.
Nessun test unit (il controller dipende da classi framework non stubbate).

## 13. Gestione errori e retry

- Generazione PDF, invio al provider ed elaborazione delle callback sono gestite in modo
  asincrono (coda su DB, §5) con **log e retry automatici** (numero tentativi/backoff da
  definire).
- **Classificazione errori (2026-06-12)**: gli errori sono distinti in *retryable* (provider
  irraggiungibile, timeout, 5xx → retry con backoff fino a N tentativi, poi stato `error` +
  notifica admin) e *non-retryable* (tag non trovato nel PDF template, telefono firmatario
  mancante/invalido, credenziali errate → stato `error` immediato senza retry, sono errori di
  configurazione/dato che il retry non risolve).
- L'utente/admin viene informato solo in caso di completamento o di fallimento definitivo
  dell'operazione.

## 13-bis. Sicurezza (2026-06-12)

- **PDF generati/firmati fuori dal web root servito**: vedi §12. Mai link diretti al
  filesystem; sempre download via controller autorizzato.
- **Callback provider** (endpoint frontend non autenticato): difesa su tre livelli:
  1. **token segreto per documento** nell'URL di callback (random, salvato sul record
     documento, confronto constant-time);
  2. **allowlist IP** opzionale da configurazione;
  3. la callback è un **ping**: lo stato reale viene sempre letto dall'API del provider, mai
     dal payload della callback.
  L'endpoint risponde sempre HTTP 200 (anche su errore interno) e richiede esenzione CSRF
  (`CsrfAwareActionInterface`).
- **Credenziali provider** in configurazione con backend model `Encrypted` (mai in chiaro in
  DB/dump).
- **Upload template**: estensione+mime forzati a PDF, ACL dedicata, nomi file sanificati
  (infrastruttura uploader di Magento).
- **Privacy/GDPR**: email/telefono firmatario salvati sul documento rientrano nella retention
  configurabile (§12); nei log non vanno scritti dati personali oltre il necessario.

### Decisioni di hardening confermate (2026-06-12)

- **Ordini guest**: nessun download dal sito per i guest — il documento firmato arriva loro
  esclusivamente dai canali del provider di firma. Il download nell'area "i miei ordini" è
  riservato ai clienti registrati.
- **Anti-flooding callback**: risposta uniforme HTTP 200 qualunque sia l'esito (nessun oracle
  sulla validità del token); nessun job accodato se il token non corrisponde o se il documento
  è in stato finale; **dedup**: al massimo un job di polling pendente per documento. Costo per
  richiesta ostile: una SELECT.
- **Hardening processing PDF**: parsing solo nel worker della coda (mai nella richiesta web)
  con memory limit e try/catch → errore non-retryable. **Validazione a monte all'upload,
  prima del parser**: controllo magic bytes/header `%PDF`, dimensione, versione PDF
  supportata e presenza di almeno un tag riconoscibile (ricerca raw nel contenuto); solo se
  questi controlli passano il file viene accettato. Il merchant scopre un template
  inutilizzabile all'upload, non al primo ordine.
- **Iniezione nel tag**: email firmatario validata strettamente (formato RFC, niente caratteri
  di controllo) prima della sostituzione nel PDF; telefono validato/normalizzato prima
  dell'invio al provider.
- **Download sicuro**: `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff`;
  path del file sempre risolto dal record documento, mai da parametri della richiesta.
- **Igiene log**: mai bearer token/credenziali nei log (mask nei debug delle chiamate API);
  la tabella `document_log` (payload raw con dati personali) rientra nella retention.
- **Dummy provider**: selezionabile solo con flag di configurazione esplicito, con warning
  visibile in admin quando è in uso.

### Il provider come confine di fiducia (2026-06-12, 3° round — confermato)

Tutto ciò che arriva dal provider di firma è **input non fidato**:

- **PDF firmato di ritorno**: validazione prima del salvataggio (magic bytes `%PDF`, cap
  dimensione, content-type) — un account provider compromesso non deve trasformarci in
  canale di distribuzione di file arbitrari verso i clienti.
- **Stored XSS via dati provider**: stati, messaggi d'errore e payload restituiti dal
  provider sono renderizzati in admin (griglie, tab ordine) e nelle email di errore →
  escaping sistematico ovunque; email solo tramite template Magento con variabili escapate.
- **Client HTTP del connettore**: verifica certificati TLS sempre attiva, nessun follow di
  redirect cross-host (un redirect malevolo dirotterebbe il bearer token), timeout stretti,
  validazione strutturale del JSON di risposta prima dell'uso.
- **Token callback hashati**: in DB si salva `sha256(token)`, l'URL porta il token in chiaro,
  confronto sull'hash constant-time. Un dump del DB non permette di forgiare callback.

Ulteriori decisioni dello stesso round:

- **Messaggi in coda minimali**: solo l'ID documento, in JSON (mai oggetti serializzati PHP);
  il worker ricarica sempre lo stato corrente dal DB.
- **Disclosure frontend**: il cliente vede solo messaggi generici; dettagli tecnici solo in
  log/notifiche admin (regola implementata una volta nel layer di presentazione).
- **Throttling verso il provider**: cap configurabile di chiamate API per run del consumer
  (un ordine da N righe non genera N chiamate in raffica).
- **Note di consapevolezza**: (a) su Magento Open Source le ACL non sono scopate per
  store/website — un admin ristretto vede tutti i template/documenti; limite di piattaforma,
  documentato. (b) `composer audit` in CI per le dipendenze.

### Firma obbligatoria per template (2026-06-12 — confermato)

Ogni template ha un flag **"Firma obbligatoria"** (`is_required`):

- **Obbligatorio**: il documento viene sempre generato al trigger, indipendentemente dalla
  scelta del cliente; al checkout l'indicazione è informativa/non deselezionabile.
- **Facoltativo**: il documento viene generato solo se il cliente ha selezionato la richiesta
  di firma.
- In entrambi i casi l'**enforcement è server-side al place dell'ordine**: la scelta del
  cliente registrata sul quote viene validata (ownership del quote inclusa), mai fidandosi
  del solo input frontend.

### Modalità "produzione" del modulo e GDPR (2026-06-12)

Il modulo ha un flag di **modalità produzione** attivabile solo spuntando una **presa d'atto
obbligatoria** in configurazione: il merchant dichiara di avere un DPA con il provider di
firma e di aver aggiornato la propria privacy policy (invio di PII — email, telefono — e
contratti a un fornitore terzo). Finché la presa d'atto non è spuntata, il modulo opera solo
in modalità test (provider dummy/ambienti demo).

**Implementato (2026-07-03)**: campo `digital_signature/general/production_ack` (Yesno, default
`0`) in system.xml/config.xml, subito dopo "Abilitato". Servizio
`Model\Service\ProductionModeGuard::resolveProviderCode(configuredProviderCode, storeId)`:
se la presa d'atto non è confermata per lo store, ignora il provider configurato in
"Provider di firma attivo" e forza `Dummy::CODE`, loggando un warning. Agganciato nei due
punti che decidono il provider di un documento: `TriggerHandler::handle()` (creazione da
trigger automatico/manuale) e `DocumentManager::regenerate()` (rigenerazione, eredita il
provider del documento sostituito passandolo comunque dal guard). Resta invariato il flag
esistente "Consenti provider dummy" (`providers/dummy/allow`): il gate GDPR non lo forza
implicitamente, quindi se il merchant non ha mai attivato quel flag e non ha confermato la
modalità produzione, i documenti restano creati con `provider_code=dummy` ma falliscono
nell'invio con errore permanente (notifica admin) — comportamento voluto: nessuna firma reale
può avvenire senza un'azione esplicita in configurazione. Test unit in
`TriggerHandlerTest::testProviderIsForcedToDummyWhenProductionNotConfirmed` e
`DocumentManagerTest::testRegenerateForcesDummyWhenProductionNotConfirmed`.

## 13-ter. Strategia di test (2026-06-12)

- **Unit (PHPUnit)**: parti pure — parser/sostituzione tag PDF, mappatura stati, risoluzione
  trigger (globale→template→prodotto), client provider con HTTP mockato.
- **Integration (framework Magento)**: schema, repository, observer sui trigger, ACL;
  eseguibili nell'ambiente Docker locale con DB di test dedicato.
- **End-to-end**: flusso completo ordine→documento→firma→stato usando il **provider dummy**
  (§6), senza credenziali esterne.
- **Analisi statica**: phpcs con Magento Coding Standard (già disponibile nel vendor Mage-OS).

## 14. Multi-store / multi-lingua

I template documento sono sempre multilingua: ogni template può avere file PDF differenti per
store view/lingua, secondo lo schema "valore per store view" tipico di altre entità Magento con
scope a livello store.

## 14-bis. Compatibilità versioni e temi (2026-06-12)

### Versioni core

- **Floor confermato: Magento 2.4.6+ / Mage-OS 1.0+ / PHP 8.1+**. Il codice resta su sintassi
  PHP 8.1-compatibile; vincolo composer `~8.1.0||~8.2.0||~8.3.0||~8.4.0`.
- Solo API stabili e service contract (mai classi `@internal`); declarative schema; nessuna
  dipendenza da moduli che Mage-OS esclude dalla distribuzione (dipendiamo solo da Sales,
  Catalog, Store, Ui). Mage-OS è drop-in compatibile a livello di API moduli: non è un asse
  di compatibilità separato.
- Verifica con **matrice CI** (extdn GitHub Actions: phpcs, phpstan, integration su N
  versioni) quando il modulo avrà un repo remoto; in locale si testa su Mage-OS 3.0.
- Versionamento semver del modulo + CHANGELOG; eventuali incompatibilità accertate dichiarate
  via `conflict` in composer. Mai version-sniffing a runtime: feature detection via interfacce.

### Temi: core theme-agnostic + satelliti

| Pacchetto | Contenuto | Priorità |
|---|---|---|
| `module-firma-digitale` (core) | Tutta la logica + frontend **Luma** (Knockout/LayoutProcessor) | **Beta** |
| `module-firma-digitale-hyva` | Compat tema Hyvä (Alpine/Tailwind): cart/minicart, area ordini | Fase 2 |
| `module-firma-digitale-hyva-checkout` | Componente per Hyvä Checkout (Magewire) | Fase 2 |
| `module-firma-digitale-breeze` | Compat tema Breeze | Fase 3 |

La **beta gira su Mage-OS + Luma**; Hyvä a seguire (per i test servono i pacchetti/licenze
Hyvä), Breeze come terzo satellite. Nel monorepo: `src/`, `src-hyva/`, `src-hyva-checkout/`,
`src-breeze/` montate separatamente. Touchpoint frontend ridotti a tre: checkbox
cart/minicart/checkout, link download in "i miei ordini", endpoint callback (headless).

## 15. Punti aperti / da approfondire

- ~~UI del form "Template documento"~~ → prima proposta implementata in codice (2026-06-12),
  in revisione.
- ~~Recupero documentazione WsSign~~ → fatto, vedi `docs/wssign-api.md`.
- Valori enum stati WsSign e `signatureType` alternativi a OTP_SMS: da rilevare empiricamente
  sull'ambiente demo (nessuna doc ufficiale).
- Numero di tentativi/backoff per i retry.
- Valori di retention documenti.
- Modalità esatta di selezione/richiesta firma in cart/minicart/checkout (anche Hyva), in
  funzione degli scope dei template attivi sugli articoli in carrello.
- Protezione via controller anche dei PDF template in media (fase 2).
