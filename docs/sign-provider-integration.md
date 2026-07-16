# Guida per agenzie terze: sviluppare un nuovo provider di firma

Questo documento spiega come un'agenzia terza può realizzare un connettore per un
proprio servizio di firma elettronica/digitale (analogo a DocuSign, YouSign,
Namirial, ecc.) compatibile con **MageOS_DigitalSignature**, senza modificare il
codice del modulo core.

L'architettura dei provider segue lo stesso pattern "pluggable" usato dai metodi
di pagamento in Magento: un'interfaccia comune, un pool di connettori registrato
via `di.xml`, e un modulo satellite indipendente per ogni nuovo provider.

Se cerchi invece l'elenco dei connettori già integrati o pianificati (come Adobe Acrobat Sign), consulta il documento [Connettori di Firma Digitale Disponibili e Pianificati](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/available-connectors.md).

## 1. Il contratto: `SignProviderInterface`

File di riferimento: `src/Api/SignProviderInterface.php`.

```php
interface SignProviderInterface
{
    public function getCode(): string;
    public function getLabel(): string;
    public function isEnabled(?int $storeId = null): bool;

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult;
    public function fetchStatus(DocumentInterface $document): StatusResult;
    public function downloadSignedPdf(DocumentInterface $document): string;
    public function cancel(DocumentInterface $document): void;
    public function mapStatus(string $providerStatus): ?string;
}
```

**Vincolo architetturale fondamentale** (dal docblock dell'interfaccia): *i
connettori NON modificano il documento e NON accedono allo storage*. Ricevono
contenuti come stringhe binarie e restituiscono DTO di risultato; salvare lo
stato, il PDF firmato, ecc. è responsabilità del servizio chiamante
(`DocumentProcessor`), non del provider. Un'agenzia terza non deve mai scrivere
direttamente su `DocumentInterface` o sullo storage — solo leggere i dati del
documento (email firmatario, id processo, store) e restituire i DTO richiesti.

### Semantica attesa di ogni metodo

| Metodo | Quando viene chiamato | Cosa deve fare |
|---|---|---|
| `getCode()` | Sempre | Ritorna l'identificativo univoco (es. `"miofornitore"`), usato come chiave nel pool e come prefisso della configurazione (`digital_signature/providers/miofornitore/*`). |
| `getLabel()` | UI admin (select provider) | Etichetta leggibile, tradotta. |
| `isEnabled()` | Prima di ogni operazione | Deve verificare che il provider sia configurato/abilitato (flag `enabled` + credenziali presenti). |
| `start()` | Quando un documento passa da `GENERATED` a `SENT` | Carica il PDF (parametro `$pdfContent`, binario), avvia il processo di firma, **deve incorporare l'URL di callback** (vedi §6) nella richiesta al servizio esterno. Ritorna `StartResult` con l'id di processo remoto. |
| `fetchStatus()` | Polling (refresh/reconcile) | Interroga lo stato presso il servizio esterno, ritorna `StatusResult` con lo stato grezzo (stringa specifica del provider). |
| `downloadSignedPdf()` | Solo quando `mapStatus()` risolve a `Status::SIGNED` | Scarica e ritorna il PDF firmato (binario). |
| `cancel()` | Rigenerazione/reinvio di un documento attivo | Annulla il processo remoto. **Deve essere idempotente**: non deve fallire se il processo non esiste più (già cancellato/scaduto lato provider). |
| `mapStatus()` | Dopo ogni `fetchStatus()` e nella callback | Traduce lo stato grezzo del provider in una delle costanti `Model\Document\Status::*`. Ritorna `null` se lo stato non è riconosciuto (verrà loggato, nessuna transizione applicata). |

### DTO di ritorno

- `Model/Provider/Result/StartResult.php` — immutabile, espone `getProcessId()` (id
  del processo lato provider, salvato su `providerProcessId`) e
  `getRawResponse()` (per audit/log).
- `Model/Provider/Result/StatusResult.php` — espone `getRawStatus()` (stringa da
  passare a `mapStatus()`) e `getRawResponse()`.

## 2. Registrazione nel Pool (`di.xml`)

`src/Api/SignProviderPoolInterface.php`:

```php
interface SignProviderPoolInterface
{
    /** @return SignProviderInterface[] indicizzati per codice */
    public function getProviders(): array;

    /** @throws NoSuchEntityException se il codice non è registrato */
    public function get(string $code): SignProviderInterface;
}
```

L'implementazione (`Model/Provider/Pool.php`) valida a runtime che ogni elemento
iniettato implementi `SignProviderInterface`, altrimenti lancia
`\InvalidArgumentException` — utile per intercettare subito un errore di
configurazione nel proprio modulo.

I provider **core** sono registrati in `src/etc/di.xml`:

```xml
<type name="MageOS\DigitalSignature\Model\Provider\Pool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="wssign" xsi:type="object">MageOS\DigitalSignature\Model\Provider\WsSign</item>
            <item name="dummy" xsi:type="object">MageOS\DigitalSignature\Model\Provider\Dummy</item>
        </argument>
    </arguments>
</type>
```

Un'agenzia terza **non modifica questo file**: nel `di.xml` del proprio modulo
satellite aggiunge lo stesso `<type>` con un `<item>` aggiuntivo (Magento
unisce gli array `di.xml` di più moduli sullo stesso nodo):

```xml
<!-- Vendor/ModuloMioFornitore/etc/di.xml -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="MageOS\DigitalSignature\Model\Provider\Pool">
        <arguments>
            <argument name="providers" xsi:type="array">
                <item name="miofornitore" xsi:type="object">Vendor\ModuloMioFornitore\Model\Provider\MioFornitore</item>
            </argument>
        </arguments>
    </type>
</config>
```

Il modulo satellite deve dichiarare una dipendenza (`<sequence>`) su
`MageOS_DigitalSignature` in `module.xml`, così l'interfaccia e le classi base
sono già caricate.

## 3. Esempio minimo da cui partire: il provider `Dummy`

`src/Model/Provider/Dummy.php` è il connettore più semplice del modulo (nessuna
chiamata di rete reale) ed è il punto di partenza consigliato per capire lo
scheletro di un provider:

- `const CODE = 'dummy'` — costante di codice, coerente con la chiave nel pool.
- Stati grezzi propri (`RAW_IN_PROGRESS`, `RAW_SIGNED`) — ogni provider ha i
  **propri** stati grezzi arbitrari, non esiste un vocabolario condiviso: la
  traduzione verso gli stati interni avviene solo in `mapStatus()`.
- `start()` valida il contenuto ricevuto, genera un process id sintetico e
  ritorna `new StartResult($processId, $rawResponse)`.
- `fetchStatus()` decide lo stato grezzo attuale e ritorna `new
  StatusResult($rawStatus, $rawResponse)`.
- `downloadSignedPdf()` ritorna il contenuto binario del PDF firmato.
- `cancel()` è un no-op (nessuno stato remoto reale da annullare) — un provider
  reale invece deve chiamare l'API di cancellazione del servizio esterno.
- `mapStatus()` usa un semplice `match` esplicito.
- Guardia `assertAllowed()` privata, chiamata da ogni metodo pubblico tranne i
  getter/`isEnabled()`/`mapStatus()`: lancia `ProviderException::permanent()` se
  il provider non è esplicitamente abilitato in configurazione.

## 4. Esempio production-grade: `WsSign` + `Client`

Per un connettore reale (chiamate HTTP a un servizio esterno), il riferimento è
`src/Model/Provider/WsSign.php` + `src/Model/Provider/WsSign/Client.php`. Punti
di hardening che ogni nuovo provider dovrebbe replicare (dal docblock del
Client):

- **TLS verify sempre attivo** (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`).
- **Nessun follow di redirect** (`CURLOPT_FOLLOWLOCATION = false`) — un
  redirect malevolo/compromesso potrebbe altrimenti dirottare token/credenziali
  verso un host diverso.
- **Timeout stretti** (connect + totale) per non bloccare i consumer di coda.
- **Risposte JSON validate strutturalmente** prima dell'uso (mai fidarsi
  ciecamente del body: il provider è un confine di fiducia esterno).
- **Nessun segreto nei messaggi d'errore** (né nei log, né nelle eccezioni
  mostrate all'utente).
- URL della piattaforma validato e **obbligatoriamente `https://`** a livello
  applicativo, non solo affidandosi al TLS del client HTTP.

`WsSign::start()` costruisce l'URL di callback così:

```php
$callBackUrl = $baseUrl . 'digitalsignature/callback/index/id/' . $document->getId()
    . '/token/' . $callbackToken;
```

Questo è il pattern che **ogni** provider deve seguire: l'URL di callback (§6)
va costruito con l'id del documento e il `$callbackToken` ricevuto come
parametro di `start()`, e passato al servizio esterno perché lo richiami a
processo concluso/variato.

Validazione dati firmatario specifica del provider (non nell'interfaccia
generica — ogni provider decide da sé cosa richiede):
`requireValidEmail()` (regex + `FILTER_VALIDATE_EMAIL` + controllo caratteri di
controllo) e `requireValidPhone()` (normalizzazione prefisso internazionale,
fallback a un prefisso di default configurabile, minimo 6 cifre).

## 5. Gestione errori: `ProviderException`

`src/Exception/ProviderException.php`:

```php
class ProviderException extends LocalizedException
{
    public static function retryable(Phrase $phrase, ?\Exception $cause = null): self;
    public static function permanent(Phrase $phrase, ?\Exception $cause = null): self;
    public function isRetryable(): bool;
}
```

Non esistono sottoclassi: la distinzione avviene tramite i due factory method.
**Regola per un provider terzo**:

- `ProviderException::retryable(...)` → errori di rete, timeout, HTTP 5xx/429,
  qualunque cosa transitoria che ha senso ritentare automaticamente.
- `ProviderException::permanent(...)` → dati non validi (email/telefono),
  configurazione mancante, HTTP 4xx (eccetto 429), errori che non si
  risolverebbero ritentando.

Questa distinzione guida il retry/backoff nei consumer di coda
(`Model/Queue/Consumer/ProcessConsumer.php`): un errore `retryable` incrementa
`retry_count` fino a `max_retries` (config), un errore `permanent` porta subito
il documento in `Status::ERROR` con notifica admin.

## 6. Il flusso di callback HTTP

Il controller `src/Controller/Callback/Index.php` è **condiviso da tutti i
provider** (non c'è un controller per provider) e implementa un pattern
volutamente minimale:

- **La callback è un ping, non un payload**: il body della richiesta **non
  viene mai letto**. L'unico contratto sono due parametri nell'URL, già
  incorporati nell'URL costruito da `start()`:
  `digitalsignature/callback/index/id/<documentId>/token/<callbackToken>`.
- Il `<callbackToken>` viene verificato con confronto a tempo costante contro
  l'hash SHA-256 salvato sul documento (il token in chiaro non è mai
  persistito).
- Allowlist IP opzionale per provider (`allowed_ips` in configurazione),
  confrontata solo su `$_SERVER['REMOTE_ADDR']` (mai header spoofabili come
  `X-Forwarded-For`).
- Se i controlli passano, il controller **non chiama il provider
  direttamente**: accoda un job di refresh asincrono
  (`Publisher::publishRefresh($documentId)`), che sarà processato da
  `RefreshConsumer` → `DocumentProcessor::refreshStatus()` →
  **`provider->fetchStatus()`**.
- Risposta sempre `HTTP 200` uniforme (nessun oracolo sulla validità di
  id/token, per non facilitare enumerazione), CSRF disabilitato di proposito
  (endpoint server-to-server).

**Implicazione pratica per l'agenzia terza**: il proprio servizio di firma deve
poter chiamare (GET o POST, il body è ignorato) l'URL di callback ricevuto in
`start()` quando lo stato del processo cambia. Non serve implementare un
formato di payload specifico: la callback serve solo a innescare un
`fetchStatus()` — è **fetchStatus()**, non la callback, l'unica fonte di
verità sullo stato.

Se il proprio servizio non supporta un vero meccanismo di callback/webhook, il
provider funziona comunque tramite il **cron di riconciliazione**
(`Cron\Reconcile`, ogni 5 minuti) che ripubblica `refresh` per i documenti
`SENT` fermi da più di 15 minuti — più lento ma nessuna funzionalità persa.

## 7. Stati interni del documento

`src/Model/Document/Status.php`:

```php
public const PENDING   = 'pending';
public const GENERATED = 'generated';
public const SENT      = 'sent';
public const SIGNED    = 'signed';
public const DECLINED  = 'declined';
public const EXPIRED   = 'expired';
public const ERROR     = 'error';
public const CANCELED  = 'canceled';

public const FINAL_STATES = [self::SIGNED, self::DECLINED, self::EXPIRED, self::CANCELED];
```

`mapStatus()` **deve sempre** ritornare una di queste costanti (o `null`).
Nota: `DocumentProcessor::refreshStatus()` applica una transizione **solo**
se lo stato mappato è diverso da quello attuale **e** è uno stato finale
(`Status::isFinal()`) — stati intermedi grezzi del provider (es. "in corso di
firma", "in attesa OTP") possono essere mappati a `null` (ignorati) oppure a
uno stato non finale, che comunque non genera transizione: il documento resta
visivamente `SENT` finché non arriva un esito finale.

`ERROR` **non** è incluso in `FINAL_STATES` — può essere ripreso per un nuovo
tentativo (retry/rigenerazione manuale).

## 8. Configurazione: `system.xml`

Pattern da replicare (vedi `src/etc/adminhtml/system.xml`, gruppo
`providers`): un sotto-gruppo per provider, dentro `<group id="providers">`:

```xml
<group id="miofornitore" translate="label" type="text" sortOrder="30"
       showInDefault="1" showInWebsite="1" showInStore="1">
    <label>Mio Fornitore</label>
    <field id="enabled" type="select" sortOrder="10" ...>
        <label>Abilitato</label>
        <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
    </field>
    <field id="api_key" type="obscure" sortOrder="20" ...>
        <label>API Key</label>
        <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
        <depends><field id="enabled">1</field></depends>
    </field>
    <!-- ... altri campi, tutti con <depends><field id="enabled">1</field></depends> -->
    <field id="status_mapping" sortOrder="90" ...>
        <label>Mappatura stati</label>
        <frontend_model>MageOS\DigitalSignature\Block\Adminhtml\Form\Field\StatusMapping</frontend_model>
        <backend_model>Magento\Config\Model\Config\Backend\Serialized\ArraySerialized</backend_model>
    </field>
</group>
```

Regole importanti:

- **Path risultante**: `digital_signature/providers/miofornitore/<campo>` — letto
  tramite l'helper `Model/Provider/ProviderConfig.php` (metodi `get()`,
  `isSetFlag()`, `getSecret()` per campi `Encrypted`, `getSerialized()` per
  `ArraySerialized`). Un provider terzo può iniettare `ProviderConfig` e
  chiamare questi metodi passando il proprio codice provider.
- **Credenziali sempre con `backend_model =
  Magento\Config\Model\Config\Backend\Encrypted`** (mai testo in chiaro nel
  DB).
- **Mappatura stati a righe dinamiche** (opzionale ma consigliata se il
  servizio esterno usa nomi di stato non prevedibili a priori): riusa lo stesso
  `frontend_model`/`backend_model` di WsSign, così l'admin ha un editor a
  righe "stato grezzo → stato interno" senza bisogno di deploy per ogni nuovo
  stato scoperto in produzione.
- **Riquadro logo/descrizione/link** (introdotto per WsSign/Dummy): aggiungere
  un campo `info` in cima al proprio gruppo, con `frontend_model` che estende
  `MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo\AbstractProviderInfo`
  (vedi `Block/Adminhtml/System/Config/ProviderInfo/WsSign.php` come esempio):

  ```xml
  <field id="info" sortOrder="5" showInDefault="1" showInWebsite="1" showInStore="1">
      <frontend_model>Vendor\ModuloMioFornitore\Block\Adminhtml\System\Config\ProviderInfo\MioFornitore</frontend_model>
  </field>
  ```

  La classe implementa 5 metodi (`getLogoUrl()`, `getProviderName()`,
  `getDescription()`, `getLinks()`, `getLegalLevel()`); il logo va
  referenziato con `$this->getViewFileUrl('Vendor_Modulo::images/logo.svg')`
  da un asset in `view/adminhtml/web/images/` del proprio modulo — funziona
  sia in developer mode sia dopo `setup:static-content:deploy` in
  produzione.

  **`getLegalLevel()`** (introdotto 2026-07-14): etichetta breve del livello
  di firma offerto dal provider, in terminologia eIDAS quando applicabile
  (es. `"Firma Elettronica Avanzata (AES)"`, `"Firma Elettronica
  Qualificata (QES)"`, o `"Nessuna validità legale — solo test/sviluppo"`
  per un provider fittizio). Resa nel template come riga distinta dalla
  descrizione discorsiva, non sepolta nel testo. **Dichiara solo quanto
  effettivamente noto/documentato dal fornitore**: il box mostra comunque
  un disclaimer fisso che invita il merchant a verificare la
  certificazione esatta col proprio provider — un'agenzia terza non deve
  presentare questo campo come una garanzia di conformità legale da parte
  del modulo.

Infine, il campo globale `digital_signature/general/production_ack`
(`ProductionModeGuard`) forza **tutti** i documenti sul provider `dummy`
finché non è confermata la presa d'atto GDPR, indipendentemente da quale
provider sia selezionato in `general/provider`: un'agenzia terza non deve
prevedere alcun bypass a questo meccanismo, è intenzionale.

## 9. Riepilogo: cosa deve consegnare un'agenzia terza

Un modulo satellite completo per un nuovo provider include tipicamente:

1. `etc/module.xml` con `<sequence><module name="MageOS_DigitalSignature"/></sequence>`.
2. `etc/di.xml` con l'`<item>` aggiuntivo nel pool (§2).
3. `Model/Provider/MioFornitore.php` che implementa `SignProviderInterface` (§1),
   eventualmente con un `Client` HTTP dedicato hardenizzato come WsSign (§4).
4. `etc/adminhtml/system.xml` con il proprio gruppo dentro `providers` (§8),
   incluso il riquadro informativo (§8, ultimo punto).
5. `i18n/it_IT.csv` (+ altre lingue) per le proprie stringhe.
6. Test unitari standalone (vedi `src/Test/Unit`, harness senza dipendenza da
   Magento reale) per `mapStatus()` e la logica di validazione/errori.

Nessuna di queste modifiche tocca file del modulo core
`MageOS_DigitalSignature`: l'intero punto di estensione è
`SignProviderPoolInterface` + `di.xml` merge.
