# Catalogo "Connettori disponibili" nel modulo core

Data: 2026-07-14

## Contesto

Sessione di roleplay "merchant che valuta il modulo" (2026-07-14): tra le
aspettative deluse è emerso che l'unico provider di firma pronto all'uso è
**WsSign** (sponsor del progetto) + **Dummy** (solo test) — nessun provider
internazionale riconosciuto (DocuSign, Adobe Acrobat Sign, ecc.). Discusso un
elenco di candidati; scelto **Adobe Acrobat Sign** come primo provider
internazionale da aggiungere (fit tecnico: lavoriamo già di PDF puro, e non è
un concorrente diretto di WsSign sul segmento italiano/QES).

Decisione architetturale: i nuovi provider **non entrano nel modulo core**
(a differenza di WsSign/Dummy, che restano dov'è oggi per preservare la
visibilità dello sponsor) — vengono sviluppati come **moduli satellite
indipendenti**, seguendo il pattern pluggable già documentato in
`docs/sign-provider-integration.md` (`SignProviderInterface` + merge di
`di.xml` sul pool, esattamente come i metodi di pagamento).

**Questo spec copre SOLO** l'aggiunta, nel modulo core, di un'indicazione
visibile (doc + UI admin) che tali connettori esistono/sono pianificati e di
come installarli. **Il modulo satellite Adobe Acrobat Sign vero e proprio
NON è nello scope di questo spec** — è tracciato come task separato
(richiede credenziali/API OAuth2 Adobe, non ancora disponibili), da
brainstormare a parte quando si deciderà di realizzarlo.

## Decisioni chiave

- **WsSign resta l'unico provider realmente "di serie" nel core**, in cima
  al gruppo `providers` di `system.xml`, nessuna modifica al suo
  posizionamento o al suo box informativo esistente.
- Il catalogo mostra **Adobe Acrobat Sign** con stato "in sviluppo/non
  ancora disponibile" **più** una riga statica generica che rimanda alla
  guida per sviluppatori terzi (`sign-provider-integration.md`), per
  chiunque voglia farsi costruire un connettore diverso (DocuSign, Namirial,
  ecc.) già oggi.
- Contenuto **doppio target**: doc markdown nel repo (per chi fa il deploy o
  valuta il modulo prima di installarlo) + box nell'admin (per il merchant
  che naviga la configurazione senza mai aprire un file di doc).
- Nessuna estensione dell'architettura provider esistente
  (`SignProviderPoolInterface`/`Pool` invariati): è un catalogo statico e
  puramente informativo, disaccoppiato dai provider realmente registrati.

## Architettura

Catalogo hardcoded in una classe PHP pura (nessuna chiamata esterna, nessuno
stato), esposto sia a un nuovo campo `system.xml` sia — se in futuro
servisse altrove — riusabile senza dipendere dal contesto admin.

### Componenti nuovi

| Componente | Ruolo |
|---|---|
| `Model/Connector/AvailableConnector.php` | DTO immutabile: `code`, `name`, `status`, `description`, `link` |
| `Model/Connector/AvailableConnectorsCatalog.php` | Ritorna `AvailableConnector[]` hardcoded. Oggi: una sola voce (Adobe Acrobat Sign, status "in sviluppo"). Estendere in futuro = aggiungere una riga, nessuna modifica XML necessaria |
| `Block/Adminhtml/System/Config/AvailableConnectors.php` | Legge il catalogo; espone anche il link statico fisso alla guida sviluppatori terzi |
| `view/adminhtml/templates/system/config/available_connectors.phtml` | Rendering: nome, badge di stato, descrizione, link — per ogni voce del catalogo, più la riga statica "vuoi un connettore diverso?" |
| `etc/adminhtml/system.xml` (modificato) | Nuovo `field id="available_connectors"` nel gruppo `providers`, **sortOrder dopo WsSign e Dummy** (stessa pagina, nessun click aggiuntivo, ma non compete visivamente con WsSign) |
| `docs/available-connectors.md` (nuovo) | Stesso contenuto in forma testuale, per chi legge il repo prima di installare |
| `docs/sign-provider-integration.md` (modificato) | Link incrociato in cima: "cerchi un elenco di connettori già pronti/pianificati? vedi available-connectors.md" |

### Data flow

Nessun flusso runtime dinamico: `AvailableConnectorsCatalog::getAll()` ritorna
un array statico ad ogni chiamata; il Block lo legge in `getConnectors()` e
il template itera. Nessuna cache, nessuna configurazione, nessuna chiamata
esterna: il costo di aggiornamento è editare l'array PHP quando cambia lo
stato di un connettore (es. Adobe Acrobat Sign passa da "in sviluppo" a
"disponibile" con link al repo reale).

### Gestione errori

Nessuna: dati statici in memoria, nessun input esterno, nessun caso di
errore runtime da gestire.

### Testing

Unit test per `AvailableConnectorsCatalog` (pura logica PHP, nessuna
dipendenza Magento — coerente con gli altri test del modulo in
`src/Test/Unit`): verifica che il catalogo contenga la voce Adobe Acrobat
Sign con lo stato atteso, e che ogni voce abbia tutti i campi valorizzati
(nessun placeholder vuoto).

Nessun test per `Block/Adminhtml/System/Config/AvailableConnectors.php` o il
template: coerente con la convenzione già verificata nel modulo (zero test
automatici su tutti i 24 controller/block admin esistenti, per assenza di
stub Magento sufficienti nel bootstrap standalone di `tests/`).

## Fuori scope

- Il modulo satellite Adobe Acrobat Sign reale (connettore funzionante,
  OAuth2, mapping stati) — task separato, tracciato indipendentemente.
- Qualunque meccanismo di discovery dinamica dei satelliti installati (il
  catalogo qui è solo per connettori **non ancora installati/pianificati**;
  i provider realmente installati sono già visibili tramite il meccanismo
  esistente `SignProviderPoolInterface`/select provider in configurazione).
- Aggiornamento automatico dello stato "in sviluppo" → "disponibile": è
  manuale, un edit del file `AvailableConnectorsCatalog.php` quando il
  satellite Adobe Acrobat Sign sarà effettivamente pubblicato.
