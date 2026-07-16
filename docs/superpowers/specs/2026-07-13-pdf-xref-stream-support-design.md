# Supporto xref stream (PDF 1.5+) in TagReplacer

Data: 2026-07-13

## Contesto

`src/Model/Pdf/TagReplacer.php` sostituisce il tag placeholder `{WSIGN#W,H#email}`
dentro il PDF template caricato dall'admin, con la tecnica "incremental update"
(nessuna dipendenza esterna: niente FPDI/FPDF/TCPDF/pdfparser, solo `ext-zlib`).
Oggi supporta solo PDF con **cross-reference table classica** (stile PDF 1.4,
testuale). Se il PDF usa un **xref stream** (introdotto in PDF 1.5, molto comune
nei producer moderni: Word, LibreOffice, Acrobat, Ghostscript), `TemplateValidator`
rifiuta l'upload con un errore esplicito — non è un limite runtime silenzioso, ma
comunque una fascia di PDF reali che oggi il modulo non può accettare.

Vincolo di business non negoziabile: il modulo è software distribuito
pubblicamente su stack che non possiamo controllare o testare a priori (hosting
condivisi di terzi). Qualunque estensione **non deve introdurre dipendenze
composer o di sistema** (niente librerie PDF esterne, niente binari come `qpdf`).
La soluzione deve restare "fatta in casa", anche a costo di uno sviluppo più
corposo.

## Scope (fase 1)

PDF 1.5+ introduce due funzionalità separabili, che compaiono anche
indipendentemente l'una dall'altra a seconda del producer:

1. **xref stream** — la tabella di cross-reference codificata come stream
   binario invece che testo classico.
2. **object stream** — più oggetti PDF impacchettati/compressi in un unico
   stream contenitore (un tag potrebbe finire dentro un oggetto compresso, non
   in un `obj`...`endobj` diretto).

**Questa fase copre solo il punto 1 (xref stream).** I PDF che contengono anche
object stream restano esplicitamente non supportati e vengono rifiutati
all'upload con un messaggio dedicato. L'object stream è rimandato a un
intervento futuro separato.

PDF con dizionario `/Encrypt` (cifrati) sono anch'essi fuori scope e vengono
rifiutati esplicitamente all'upload.

## Decisioni chiave

### Validazione = dry-run reale (non una checklist separata)

`TemplateValidator` oggi verifica solo: dimensione massima, magic bytes `%PDF`,
presenza di un trailer classico, e che `TagReplacer::findTags()` trovi almeno un
tag (parsing strutturale, non ricerca raw). Non esegue mai la scrittura vera.

Per garantire che **un file accettato non possa mai fallire in produzione per
motivi strutturali**, la validazione viene estesa per eseguire un vero dry-run:
invoca lo stesso identico `TagReplacer` che verrà usato in produzione, con
un'email segnaposto fissa, in memoria (nessuna scrittura su disco), e richiede
che almeno una sostituzione sia avvenuta senza eccezioni. Questo sostituisce il
solo controllo di presenza tag con l'esecuzione reale del path di scrittura:
elimina strutturalmente la classe di bug "il validator dice sì, lo scrittore
dice no" perché non sono due implementazioni distinte da tenere sincronizzate,
è la stessa esecuzione.

### Anteprima scaricabile per il merchant

Nuova azione admin che rigenera **on-demand** lo stesso PDF elaborato dal
dry-run (stessa email segnaposto fissa) e lo restituisce in download, per dare
al merchant conferma visiva che la sostituzione avvenga nella posizione
corretta. Rigenerato ad ogni richiesta, non persistito: essendo deterministico,
non serve gestire storage/pulizia di un file temporaneo aggiuntivo.

### Architettura: DTO + resolver per la risoluzione xref

> **Nota (2026-07-14, post-implementazione):** la sezione seguente descrive il
> disegno originale con un'interfaccia `XrefTableInterface` a risoluzione
> offset-per-oggetto. In fase di scrittura del piano è emersa una soluzione più
> semplice — non serve risolvere l'offset di un oggetto arbitrario, perché
> `TagReplacer` continua a ritrovare gli oggetti-tag con una scansione diretta
> del testo, non passando dalla xref. Serve solo sapere, in blocco, se la
> revisione più recente è basata su xref stream (per scegliere il formato
> dell'incremental update) e se l'intera catena contiene object stream/
> `/Encrypt` (per il rifiuto). **Implementazione reale:**
> - `XrefLink` (DTO) — esito della lettura di UNA sezione (tabella o stream):
>   `size`, `root`, `hasEncrypt`, `hasObjectStreams`, `isStream`, `prevOffset`.
> - `XrefInfo` (DTO) — esito aggregato sull'intera catena `/Prev` esplorata:
>   `size`/`root`/`isStreamBased` dalla revisione più recente,
>   `hasObjectStreams`/`isEncrypted` in OR su tutta la catena.
> - `ClassicXrefReader` / `XrefStreamReader` — leggono UN link ciascuno (non
>   implementano un'interfaccia comune: `XrefChainResolver` li chiama
>   esplicitamente in base al detector `substr($pdf, $offset, 4) === 'xref'`).
> - `XrefChainResolver` — orchestratore: individua `startxref`, chiama il
>   reader giusto via detector, segue `/Prev` fino a `MAX_CHAIN_DEPTH = 2`,
>   produce `XrefInfo`.
> Nessuna interfaccia astratta: due DTO immutabili + un resolver concreto.
> Il resto di questa sezione resta come riferimento storico della discussione
> di design, non come descrizione del codice attuale.

Per isolare il nuovo codice (xref stream) dal codice esistente (xref classica,
già solido e coperto da 17 test) invece di intrecciarli:

- **`XrefTableInterface`** (nuova): dato un numero oggetto/generazione,
  risolve l'offset byte nel file. Espone anche `hasObjectStreams(): bool`
  (vero se la catena contiene entry di tipo 2, cioè oggetti dentro un object
  stream) e `isEncrypted(): bool` (vero se il trailer/dizionario xref-stream
  contiene `/Encrypt`).
- **`ClassicXrefReader`**: la logica xref classica già esistente in
  `TagReplacer`, estratta dietro l'interfaccia **senza alcun cambio di
  comportamento**. I 17 test esistenti restano una guardia di regressione.
- **`XrefStreamReader`** (nuovo): parsing del dizionario dell'oggetto
  `/Type /XRef`, decodifica binaria dei campi secondo `/W [w1 w2 w3]` e
  `/Index`, un-predictor PNG (supporto a `/Predictor 12`, il quasi-standard
  usato da Acrobat/Ghostscript/LibreOffice — un valore di predictor diverso o
  non riconosciuto è un rifiuto esplicito, non un fallback silenzioso), e
  segue la catena `/Prev` delegando ricorsivamente al reader adatto al link
  successivo (può essere ancora uno xref stream, o — caso limite — una tabella
  classica in una catena mista).
- Un piccolo componente "detector" decide, dato l'offset puntato da
  `startxref`, se si tratta di una tabella classica o di un oggetto xref
  stream, e istanzia il reader corretto.
- **`TagReplacer`**: consuma `XrefTableInterface` invece di fare parsing xref
  inline; il resto della logica (estrazione del singolo oggetto via offset,
  whitening in-place, composizione dell'incremental update) resta invariato,
  sia per il caso classico che per quello xref-stream.

### Scrittura: sempre xref stream nuovo se la sorgente lo è

Quando il documento sorgente usa xref stream, l'incremental update scritto da
`TagReplacer` aggiunge in coda anch'esso un **oggetto xref stream** (mai una
tabella classica ibrida — non sarebbe conforme allo standard per un file che
usa xref stream). Per minimizzare il rischio lato scrittura (che controlliamo
noi, a differenza della lettura che deve adattarsi a qualunque producer), il
nuovo xref stream viene scritto **senza predictor e senza compressione**:
scelta legale secondo lo standard, elimina la necessità di implementare anche
l'encoding del PNG predictor (solo la decodifica, lato lettura, è
obbligatoria).

## Componenti nuovi/modificati

**Aggiornata al codice reale (vedi nota 2026-07-14 sopra):**

| Componente | Stato | Ruolo |
|---|---|---|
| `Model/Pdf/Xref/XrefLink` | nuovo | DTO: esito lettura di una sezione xref (tabella o stream) |
| `Model/Pdf/Xref/XrefInfo` | nuovo | DTO: esito aggregato sull'intera catena `/Prev` risolta |
| `Model/Pdf/Xref/ClassicXrefReader` | nuovo (estratto) | Legge un link classico → `XrefLink`, comportamento invariato |
| `Model/Pdf/Xref/XrefStreamReader` | nuovo | Parsing/decodifica xref stream, un-predictor PNG → `XrefLink` |
| `Model/Pdf/Xref/XrefChainResolver` | nuovo | Detector + orchestrazione catena `/Prev` (max profondità 2) → `XrefInfo` |
| `Model/Pdf/Xref/StartxrefLocator` | nuovo (estratto) | Individua l'offset `startxref` nel PDF |
| `Model/Pdf/Xref/DictFields` | nuovo | Parsing campi dizionario PDF testuale (Size/Root/Prev/Encrypt/W/Index...) |
| `Model/Pdf/TagReplacer` | modificato | Consuma `XrefChainResolver`; scrive xref stream nuovo se la sorgente lo è |
| `Model/Pdf/TemplateValidator` | modificato | Rifiuta object-stream/`/Encrypt`/predictor ignoto; validazione = dry-run reale |
| `Controller/Adminhtml/Template/Preview` | nuovo | Rigenera on-demand il PDF di anteprima (dry-run), download PDF, ACL `::template` |

## Flusso dati

**Upload** → controlli economici invariati (dimensione, magic bytes) →
risoluzione della catena xref (classica e/o stream, via detector) → rifiuto
esplicito se cifrato, se sono presenti object stream, o se il predictor non è
gestito → dry-run di sostituzione in memoria con email segnaposto → upload
accettato solo se almeno un tag è stato sostituito senza errori.

**Preview (admin, on-demand)** → ricarica il PDF template già salvato →
rilancia lo stesso dry-run con la stessa email segnaposto → streamma il
risultato come download (`application/pdf`, nosniff, Content-Disposition
attachment) → nessuna persistenza.

**Elaborazione ordine reale** → stesso `TagReplacer`, stessa logica, ora
capace anche di xref stream: nessun cambiamento del flusso esistente, solo
estensione della fascia di PDF supportati.

## Gestione errori

Ogni causa di rifiuto ha un messaggio distinto e specifico (non un generico
"PDF non valido"), continuando lo stile già in uso nel modulo:

- PDF cifrato (`/Encrypt` presente) → rifiutato.
- Object stream presente nella catena xref → rifiutato ("non supportato in
  questa fase").
- Predictor non riconosciuto durante la decodifica di uno xref stream →
  rifiutato.
- Nessun tag trovato (già esistente) → rifiutato.
- Struttura malformata/non decodificabile → rifiutato.
- Dry-run che solleva un'eccezione nel path di scrittura reale → rifiutato,
  messaggio dell'eccezione originale.

Poiché la validazione ora esegue letteralmente lo stesso path di scrittura
usato in produzione, un file accettato non può più fallire per motivi
strutturali durante l'elaborazione di un ordine reale. Il solo margine di
fallimento residuo in produzione è di natura non strutturale (es. provider di
firma irraggiungibile), fuori scope di questo intervento.

## Testing

- Nuovi test unitari per `XrefStreamReader` con xref stream costruiti a mano
  (fixture byte-level), stesso stile della suite esistente di `TagReplacer`:
  - stream senza predictor;
  - `/Predictor 12` con larghezze `/W` diverse (1/2/3 byte per campo);
  - `/Index` con più range (non solo `[0 Size]`);
  - catena `/Prev` a due livelli (due xref stream concatenati);
  - rifiuto quando sono presenti entry di tipo 2 (object stream);
  - rifiuto quando è presente `/Encrypt`;
  - rifiuto quando il predictor non è riconosciuto.
- I 17 test esistenti sulla xref classica restano verdi e invariati (guardia
  di regressione sul refactor dietro interfaccia).
- Nuovi test per `TemplateValidator`: PDF classico valido accettato, PDF
  xref-stream valido accettato, PDF xref-stream con object stream rifiutato,
  PDF cifrato rifiutato.
- Serve almeno una fixture PDF **reale** con xref stream generata da un
  producer comune (es. LibreOffice `--convert-to pdf`, o Ghostscript), non
  solo byte costruiti a mano — punto aperto da risolvere in fase di piano
  implementativo (come procurarsi/generare quella fixture in modo
  riproducibile per la CI).
- Il controller `Preview` richiede verifica ACL + content-type/disposition;
  la suite attuale è standalone (stub minimi del framework Magento, niente
  Magento reale) — un test end-to-end del controller potrebbe richiedere
  verifica live nell'ambiente Docker locale, come già fatto per altri
  controller admin in sessioni precedenti di questo progetto.

## Fuori scope (rimandato)

- Supporto object stream (compressione di più oggetti in un unico stream
  contenitore) — fase successiva separata.
- Supporto PDF cifrati.
- File con catena xref "hybrid-reference" complessa oltre al caso base
  gestito dal detector (tabella classica + xref stream con `/Prev` semplice).
