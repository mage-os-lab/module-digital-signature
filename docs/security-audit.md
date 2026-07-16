# Audit di sicurezza — MageOS_DigitalSignature

Data: 2026-07-02 · Ambito: revisione statica completa di `src/` e `src-hyva/`
(controller, servizi, connettori, storage, template, XML di configurazione).

## Esito complessivo

**Nessuna falla critica o alta.** Il modulo applica correttamente le contromisure
decise in `docs/analisi.md` (§12, §13-bis, §13-ter). Un gap funzionale con
impatto di sicurezza (rigenerazione senza annullamento lato provider) è stato
**corretto in questo audit**; il resto sono raccomandazioni minori.

## Problemi trovati e corretti

### 1. [MEDIO — CORRETTO] Rigenerazione senza annullamento lato provider

`DocumentManager::regenerate()` storicizzava il documento attivo (stato
`canceled` locale) ma **non chiamava mai `SignProviderInterface::cancel()`**:
il processo di firma precedente restava attivo presso il provider e il cliente
avrebbe potuto firmare **entrambe** le versioni del contratto (la callback del
vecchio documento veniva ignorata perché in stato finale, quindi il PDF firmato
"fantasma" non sarebbe nemmeno stato scaricato). Violava la decisione di
analisi: "il documento attivo precedente va annullato/cancellato lato provider".

**Fix**: `DocumentManager::cancelAtProvider()` — annullamento best-effort al
provider dopo la storicizzazione; un errore del provider non blocca la
rigenerazione ma viene registrato nel log documento. Coperto da unit test
(`DocumentManagerTest`).

### 2. [BASSO/MEDIO — CORRETTO] Decompression bomb nel parser PDF

Trovato con test dinamico (fuzzing del parser). `TemplateValidator` limita il
PDF a 10 MB, ma uno stream `FlateDecode` di poche centinaia di KB può
espandersi a centinaia di MB (amplificazione zlib). Un PDF di **199 KB**
faceva allocare **oltre 400 MB** a `TagReplacer::findTags()` →
esaurimento memoria / DoS. Superficie admin-only (solo i merchant caricano
template), da cui la severità contenuta, ma amplificazione non limitata.

**Fix**: cap alla dimensione decompressa per singolo stream (50 MB) via il
parametro `max_length` di `gzuncompress`: gli stream oltre soglia vengono
scartati senza essere materializzati. Coperto da test (bomba con tag → scartata;
stream legittimo → letto). Le altre vie testate — ReDoS su sequenze lunghe,
200k header oggetto falsi — **non** degenerano (< 0.3 s).

### 3. [INFO — CORRETTO] Error suppression non documentata

`TagReplacer`: `@gzuncompress()` (fallback intenzionale su stream non-zlib) ora
documentato con `phpcs:ignore` motivato. Il coding standard Magento2 passa
senza errori su tutto il modulo.

## Aree verificate (nessun problema)

| Area | Verifica |
| --- | --- |
| **Callback provider** (`Controller/Callback/Index`) | Token per documento confrontato come sha256 con `hash_equals` (in DB solo l'hash); risposta HTTP 200 uniforme (nessun oracle su id/token); payload MAI usato (ping → job di polling); allowlist IP; dedup 1 job/60s per documento; guard su stati finali; CSRF exempt corretto per server-to-server. |
| **Download cliente** (`Controller/Order/DownloadSigned`) | Solo clienti loggati (no guest, §13-bis); ownership verificata via `OrderRepository→getCustomerId()`; esposti solo documenti `signed`; errori/non-proprietà → `noroute` (nessuna enumerazione); `Content-Disposition: attachment` + `nosniff`. |
| **Download admin** (`Controller/Adminhtml/Document/Download`) | ACL `::document`; i path arrivano solo dai record DB, mai da input utente. |
| **Upload template PDF** (`Controller/Adminhtml/Template/Upload`) | ACL `::template`; uploader con allowlist estensione (`pdf`) e MIME (`application/pdf`); validazione contenuto A MONTE del parser (magic bytes `%PDF`, cap 10 MB, xref classica, presenza tag); file temporaneo eliminato se la validazione fallisce. |
| **Opt-in carrello** (`Controller/Cart/SetRequested`) | POST + form key (CsrfValidator frontend); agisce solo sul quote della sessione; input ridotto a booleano. |
| **Storage documenti** (`Model/Storage/VarDocumentStorage`) | `var/digitalsignature/` non servita dal web; normalizzazione path (`..`, NUL) come difesa in profondità. |
| **Client WsSign** (`Model/Provider/WsSign/Client`) | TLS verify peer+host, `FOLLOWLOCATION` disattivato (protezione bearer token), timeout stretti, platform URL solo HTTPS validato, errori classificati retryable/permanenti, token mai nei log/messaggi, risposta JSON validata strutturalmente, PDF firmato di ritorno verificato (`%PDF`). |
| **Credenziali** | Password provider con backend `Encrypted` in system.xml. |
| **XSS** | Tutti i `.phtml` usano `escapeHtml`/`escapeUrl`/`escapeJs`/`escapeHtmlAttr`; gli output non escapati residui sono costanti/interi. I dati provenienti dal provider (stato, messaggi errore) sono sempre escapati in admin. Email: direttive `{{var}}` in strict mode (escape di default da Magento 2.3.4+). |
| **SQL injection** | Nessuna query concatenata: solo select/collection del framework con binding. |
| **Controller admin** | Tutti con `ADMIN_RESOURCE` dedicato (ACL granulare `::template` / `::document` / `::config`) e marker HTTP corretto (`HttpPostActionInterface` per le azioni mutanti → form key admin automatica). |
| **Coda/cron** | Messaggi con solo l'ID documento; retry con contatore e cap configurabile; batch limit come throttling verso il provider; consumer idempotenti (guard su stato). |
| **Enforcement scelta cliente** | Sempre server-side in `TriggerHandler::passesCustomerChoice()` (coperto da unit test): i template obbligatori generano comunque, i facoltativi solo con opt-in. |

## Raccomandazioni (non bloccanti)

1. **Callback solo POST**: oggi accetta anche GET (`HttpGetActionInterface`).
   Se WsSign chiama in POST, rimuovere il GET riduce la superficie (i token in
   URL su GET possono finire nei log degli intermediari — mitigato dal fatto
   che il token è già in URL by design WsSign e in DB c'è solo l'hash).
2. **Retention/GDPR**: la cancellazione programmata dei PDF in
   `var/digitalsignature/` e della tabella log è ancora da implementare (già in
   roadmap: gating "modalità produzione" + retention configurabile).
3. **Cookie di sessione nella risposta upload**: pattern standard
   dell'uploader Magento (flowjs), solo admin; nessuna azione, segnalato per
   completezza.
4. **`composer audit` in CI**: previsto dall'analisi §13-ter; il modulo non ha
   dipendenze runtime proprie, quindi oggi il valore è basso — riconsiderare se
   si aggiungono pacchetti.

## Test dinamici eseguiti (2026-07-03, env `../firmadigitale-magento`)

Prove attive contro l'ambiente in esecuzione (Mage-OS 3, dummy provider). Tutti
i dati di test sono stati **ripristinati** a fine sessione (documenti, ordini,
password, cliente di test, PDF fittizi).

| Test | Esito |
| --- | --- |
| **IDOR download firmato** | ✅ Cliente 1 scarica il PROPRIO documento (200, `application/pdf`); documento su ordine **guest** → 404; documento di un **altro cliente** → 404. Verifica speculare: cliente 2 scarica il proprio (200) ma non quello del cliente 1 (404). |
| **Accesso guest** | ✅ Download senza login → 302 verso `customer/account/login`. |
| **Documento inesistente** | ✅ id 99999 da cliente loggato → 404 noroute (nessuna enumerazione). |
| **Injection su parametro id** | ✅ `id="3'OR'1"` → `(int)`=3: risolve al doc del cliente loggato (200), nessuna injection; query parametrizzate. |
| **CSRF `cart/setRequested`** | ✅ POST senza form key → 302 (invalid form key); GET su endpoint POST-only → 404. |
| **Callback token errato** | ✅ HTTP 200 uniforme, nessun log/job (nessun oracle). |
| **Callback doc inesistente** | ✅ HTTP 200 uniforme. |
| **Callback token corretto** | ✅ HTTP 200, +1 log "Callback ricevuta", job di refresh accodato. |
| **Callback replay (dedup)** | ✅ Seconda chiamata entro TTL → 200 senza nuovo job. |
| **Fix anti-bomba live** | ✅ Cap `MAX_DECOMPRESSED_STREAM` presente e attivo nel container. |

## Test ancora aperti (opzionali)

Tutte e 4 le voci sono state completate il 2026-07-03 — dettagli nelle sezioni dedicate
sotto. Sintesi: ACL negativa ✅ nessun bug; upload malevolo → bug critico (funzionale, non
di sicurezza) trovato e corretto; spoofing `X-Forwarded-For` → **bug di sicurezza reale**
trovato e corretto; PHPStan → 1 bug minore di tipizzazione trovato e corretto, resto falsi
positivi sistemici Magento.

## ACL negativa (2026-07-03)

Creato un secondo admin con ruolo custom limitato a `MageOS_DigitalSignature::digitalsignature`
+ `::document` (con `deny` espliciti su `::template`/`::config`, necessari perché Zend_Acl fa
ereditare gli `allow` ai figli della risorsa radice). Verificato via HTTP: griglia documenti
(`digitalsignature/document/index`, richiede solo `::document`) → 200; griglia template
(`digitalsignature/template/index`) e configurazione (`admin/system_config/edit/section/
digitalsignature`) → 302 redirect verso "accesso negato". **ACL del modulo corretta, nessun
bug.** Utente/ruolo di test rimossi a fine test, verificato che resti solo `john.smith`.

## Spoofing `X-Forwarded-For` sull'allowlist IP callback (2026-07-03) — bug di sicurezza reale, corretto

`Controller/Callback/Index::isIpAllowed()` usava `Magento\Framework\HTTP\PhpEnvironment\
RemoteAddress::getRemoteAddress()`. **Su Mage-OS questo servizio è configurato globalmente**
(`vendor/mage-os/magento2-base/app/etc/di.xml`) con `alternativeHeaders =>
['x-forwarded-for' => 'HTTP_X_FORWARDED_FOR']` e **nessun `trustedProxies`**: qualunque
chiamante esterno può quindi impostare l'header `X-Forwarded-For` e farsi passare per un IP
a piacere, aggirando l'`allowed_ips` configurato per il provider. Test pratico confermato in
env: `allowed_ips=10.10.10.10` (diverso dal client reale) + richiesta con header
`X-Forwarded-For: 10.10.10.10` → **bypass riuscito**, callback accettata (`X-Real-IP` e
`Client-IP` invece bloccati, non sono tra gli `alternativeHeaders` configurati). Mitigante:
il `callback_token` (sha256, `hash_equals`) resta comunque obbligatorio e non è aggirabile
da questo bug — quindi l'IP allowlist era "difesa in profondità" bypassabile, non l'unico
controllo, ma l'admin che la configura si aspetta legittimamente che funzioni.

**Corretto**: `isIpAllowed()` ora legge `$_SERVER['REMOTE_ADDR']` direttamente (nuovo metodo
privato `getTrueRemoteAddress()`), ignorando `RemoteAddress` e qualunque header
proxy-spoofabile — indipendentemente dalla configurazione `di.xml` del core/tema. Se il sito
è dietro un vero reverse proxy, `REMOTE_ADDR` sarà l'IP del proxy (comportamento corretto e
sicuro di default; per vedere l'IP originale servirebbe una configurazione esplicita di
proxy fidati, fuori scope). Verificato live in env (reflection sul metodo, `REMOTE_ADDR`
impostato a un valore, `X-Forwarded-For` spoofato a un altro): il controller ora usa sempre
il primo, ignorando il secondo.

## PHPStan (2026-07-03)

Eseguito nell'env Docker (Magento reale disponibile, necessario per risolvere le classi
Factory/Proxy generate — non fattibile nell'harness PHPUnit standalone di questo repo).
Config temporanea livello 5 sui soli namespace del modulo (`Api`, `Block`, `Controller`,
`Cron`, `Exception`, `Model`, `Observer`, `Plugin`, `Ui`; `Test` escluso), con
`scanDirectories` su `generated/code` per risolvere le classi generate (Factory/Collection),
dopo un `bin/magento setup:di:compile` per generarle tutte.

- **66 → 44 errori** dopo la corretta risoluzione delle classi generate.
- **Bug reale trovato e corretto**: `ProviderException::retryable()`/`permanent()`
  dichiaravano il parametro `?\Throwable $cause`, ma lo passavano al costruttore di
  `LocalizedException` che accetta solo `?\Exception` — un `\Error` reale (mai capitato
  finora: tutti i call site catturano `\Exception`) avrebbe causato un `TypeError` invece
  di un errore gestito. Ristretto il tipo a `?\Exception`, coerente con tutti i punti di
  chiamata reali. **44 → 42 errori residui.**
- **42 errori residui = falsi positivi sistemici**, non azionabili senza un'estensione
  PHPStan dedicata a Magento (es. `mage2tv/magento2-phpstan-extension`, non installata in
  questo progetto): chiamata di metodi esistenti solo sulla classe concreta ma non
  sull'interfaccia Api dichiarata nel type-hint (`OrderInterface::getId()/getData()/
  getItemById()`, `StoreInterface::getBaseUrl()`, `RequestInterface::getPostValue()/
  getPost()`, `DataObject::getId()` — pattern universale in tutto l'ecosistema Magento,
  incluso il core stesso); `Curl::setOption()` con costanti `CURLOPT_*` (int) contro un
  type-hint `string` errato nel core Magento (`curl_setopt_array()` richiede int, quindi il
  codice del modulo è corretto, è il core ad avere il tipo impreciso); `addFieldToFilter()`
  con condizione scalare (int) contro un type-hint `array|string|null` più stretto del
  comportamento reale; `getFilter()->getValue()` su un tipo di ritorno `AbstractFilter|false`
  — pattern standard riusato identico nel core Magento per le colonne griglia.
  Raccomandazione per CI futura: integrare `mage2tv/magento2-phpstan-extension` (o
  equivalente) per eliminare questa classe di falsi positivi ed eventualmente alzare la
  soglia di errore reale rilevabile.

## Upload PDF malevolo end-to-end (2026-07-03) — bug critico trovato

Test E2E nell'env Docker sul form admin "Template documento": file senza magic bytes,
doppia estensione `.pdf.php`, PDF senza tag valido, oversize, PDF valido di controllo.
Ha rivelato che **`Controller/Adminhtml/Template/Upload::validateUploadedPdf()` non
eseguiva mai la validazione a monte** (magic bytes/xref/tag/anti-bomba): leggeva
`$uploadResult['path']`, ma `ImageUploader::saveFileToTmpDir()` del core Magento fa
`unset($result['path'])` prima di restituire — quella chiave non esiste mai. Risultato:
ogni upload, **anche di PDF validi**, falliva sempre con "il contenuto dal file non può
essere letto" — la funzione di upload template era **completamente inutilizzabile**, non
solo un gap di sicurezza (la shallow-validation di Magento su estensione/MIME restava
comunque attiva e bloccava correttamente i casi più grossolani come `.pdf.php`).
**Corretto**: il path va ricostruito da `Filesystem::getDirectoryRead(MEDIA)
->getAbsolutePath()` con la stessa `baseTmpPath` (`digitalsignature/tmp`) usata dal
virtualType `PdfUploader` in `di.xml`. Verificato live (Object Manager + reflection sul
metodo privato): PDF valido → accettato; PDF senza tag → rifiutato e file temporaneo
ripulito correttamente.

## Strumenti introdotti con questo audit

- Suite unit (`vendor/bin/phpunit`): 63 test / 136 assertion su TagReplacer
  (incl. robustezza anti-bomba e ReDoS), TemplateValidator,
  TemplateValidator, Status, ProviderException, WsSign (mapping stati), Client
  WsSign (classificazione errori HTTP), TriggerHandler (enforcement server-side)
  e DocumentManager (rigenerazione + cancel provider).
- CI GitHub Actions (`.github/workflows/ci.yml`): lint PHP 8.1–8.4, validazione
  XML, unit test, coding standard Magento2 (phpcs, solo errori).
