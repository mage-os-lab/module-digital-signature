# Inserimento tag firma in coordinate precise (backend)

Data: 2026-07-14

## Contesto

Sessione di roleplay "merchant che valuta il modulo" (2026-07-14): tra le
aspettative deluse, la preparazione del template è interamente manuale — il
merchant scrive a mano nel PDF, con un editor esterno di sua scelta, il tag
`{WSIGN#W,H#email}` indovinando le coordinate W,H (larghezza/altezza
dell'area firma in mm). Nessun builder visuale nell'admin.

Decisione di scope (discussa in sessione): la soluzione voluta è **completa**
("Opzione B") — non un semplice calcolatore di coordinate da copiare a mano,
ma un vero inserimento del tag nel PDF fatto dal sistema, nel punto e nella
pagina scelti dal merchant cliccando su un'anteprima visiva.

**Vincolo storico non negoziabile, invariato**: il modulo non introduce
dipendenze Composer o di sistema (niente librerie PDF esterne, niente
binari). Stesso vincolo che ha guidato tutto il lavoro sull'xref stream
(vedi `docs/superpowers/specs/2026-07-13-pdf-xref-stream-support-design.md`).

**Decomposizione**: il progetto si divide in due parti indipendenti.
Questo spec copre **solo la parte 1 (backend)**: la capacità di inserire un
tag firma in coordinate precise di una pagina, dato il PDF e le coordinate
già calcolate. La **parte 2 (viewer PDF in admin, cattura click/drag,
conversione pixel→punti PDF)** è un progetto separato, da brainstormare in
una sessione successiva — non è nello scope di questo documento.

## Perché serve un sottosistema nuovo (non solo estendere TagReplacer)

`TagReplacer` oggi sa solo **trovare e sostituire** un tag già presente nel
testo del PDF (scansione regex di tutti gli oggetti `N M obj ... stream ...
endstream`, mai risoluzione di un oggetto per numero/riferimento). Per
inserire un tag ex novo in una pagina scelta dal merchant, serve invece
**risolvere la struttura del documento** (quale oggetto è il content stream
della pagina N?) — capacità che il modulo non ha mai avuto: `ClassicXrefReader`
e `XrefStreamReader` leggono solo i metadati di trailer (`Size`/`Root`/
`Encrypt`/`Prev`), mai gli offset dei singoli oggetti.

## Decisioni chiave

- **Il tag inserito è testo PDF visibile e in chiaro** (operatore `Tj`/`TJ`
  con un font reale), non un trucco invisibile: coerente con come funziona
  già oggi un tag scritto a mano (resta leggibile nel PDF fino alla
  sostituzione dell'email reale in fase di generazione — vedi
  `TagReplacer::replaceSignerEmail()`, che sostituisce solo la porzione dopo
  l'ultimo `#`, lasciando `{WSIGN#W,H#...}` visibile nel testo). Nessuna
  assunzione nuova su come il provider (WsSign) localizzi il tag: si
  preserva esattamente la stessa semantica visiva già in produzione.
- **Font**: se la pagina ha già una risorsa font dichiarata, viene riusata
  (praticamente sempre vero — un contratto ha già del testo). Se non ne ha
  nessuna, si aggiunge un riferimento a un font standard PDF (Helvetica) —
  **nessun embedding necessario** (i 14 font base sono garantiti da ogni
  lettore PDF conforme), il vincolo zero-dipendenze resta intatto.
- **Alberi `/Pages` solo "piatti"** (`/Kids` che referenzia direttamente le
  pagine, nessun nodo `/Pages` annidato): coprono il caso reale più comune
  (contratti brevi da Word/LibreOffice). Struttura annidata → **rifiuto
  esplicito**, stessa filosofia già consolidata nel modulo per PDF
  cifrati/object-stream/predictor sconosciuto (mai un comportamento
  silenzioso fuori dal perimetro supportato).
- **Pagina con `/Rotate` diverso da 0 → rifiuto esplicito.** Trasformare
  correttamente le coordinate per ogni rotazione è complessità reale
  aggiuntiva, per un caso raro nei PDF contratto.
- **`/Resources` deve essere un dizionario inline sulla pagina, non un
  riferimento indiretto → rifiuto esplicito** se indiretto. Evita di dover
  aggiornare in incremental-update anche un secondo oggetto oltre al
  content stream.
- **Integrazione con l'upload (decisione già presa in sessione, "Opzione
  2")**: nessun nuovo stato "bozza" persistito. Se `TemplateValidator`
  fallisce **specificamente** per assenza di tag (non per altri motivi:
  cifrato/object-stream/dimensione), il controller di upload usa questo
  iniettore sul file tmp, poi rilancia la stessa `validate()` di oggi.
  Nessuna modifica al contratto di `TemplateValidator`.

## Architettura

### Componenti nuovi

| Componente | Ruolo |
|---|---|
| `Model/Pdf/Xref/ClassicXrefReader` (esteso) | Oltre ai metadati di trailer, espone anche la tabella `numero oggetto → offset` della sezione letta |
| `Model/Pdf/Xref/XrefStreamReader` (esteso) | Stesso arricchimento: oggi decodifica già le righe binarie (`$rows`) ma le scarta dopo aver calcolato `hasObjectStreams` — vanno esposte come tabella offset |
| `Model/Pdf/Xref/PageTreeResolver` (nuovo) | Dato PDF + numero pagina (1-based), risale `Root → /Pages → /Kids[N] → oggetto Page`, ritorna DTO con: numero/offset oggetto content-stream, dizionario `/Resources` inline, eventuale font già presente. Rifiuta esplicitamente alberi annidati, `/Rotate != 0`, `/Resources` indiretto |
| `Model/Pdf/SignatureTagInjector` (nuovo) | `injectAt(pdf, pageNumber, xPoints, yPoints, widthMm, heightMm): string`. Usa `PageTreeResolver`, costruisce il nuovo testo `Tj` (font riusato o Helvetica aggiunto), applica lo stesso meccanismo di incremental update già usato da `TagReplacer` (sbianca oggetto originale, appende nuova revisione + xref) |
| `Model/Pdf/IncrementalUpdateWriter` (nuovo, refactor) | Logica di "sbianca oggetto + appendi nuova sezione xref (classica o stream, secondo il formato sorgente)" **estratta da `TagReplacer`** e condivisa con `SignatureTagInjector`, invece di duplicarla. `TagReplacer` viene modificato per usare questo helper internamente — comportamento esistente invariato, coperto dai test già presenti |

### Data flow

1. Upload PDF → `TemplateValidator::validate()` come oggi.
2. Se fallisce **solo** per "nessun tag trovato" → controller invoca
   `SignatureTagInjector::injectAt()` con le coordinate ricevute (dalla parte
   2, fuori scope qui) sul file tmp.
3. Se l'iniezione ha successo, si rilancia `validate()` sul risultato (ora
   trova il tag, dry-run passa) → file allegato al form esattamente come un
   upload riuscito oggi.
4. Se l'iniezione fallisce (struttura non supportata: albero annidato,
   rotazione, resources indirette) → errore esplicito mostrato al merchant,
   nessun builder disponibile per quel PDF (dovrà comunque prepararlo a
   mano, come oggi).

### Gestione errori

Tutti i casi non supportati sollevano `LocalizedException` con messaggio
orientato al merchant (stesso stile di `TemplateValidator`), mai un
fallback silenzioso. Nessuna nuova categoria di errore: si riusa lo stile
già stabilito (`PDF non supportato: ...`).

### Testing

Unit test puri (PHP, nessuna dipendenza Magento), stesso stile del lavoro
xref-stream appena chiuso:
- `PageTreeResolver`: alberi piatti validi (1, 2, N pagine), rifiuto alberi
  annidati, rifiuto `/Rotate != 0`, rifiuto `/Resources` indiretto.
- `SignatureTagInjector`: font riusato da risorse esistenti vs font
  Helvetica aggiunto quando assente; round-trip completo — il tag inserito
  viene ritrovato da `TagReplacer::findTags()` e sopravvive a
  `replaceSignerEmail()` con l'email reale.
- Regressione: `IncrementalUpdateWriter` estratto da `TagReplacer` non deve
  cambiare alcun comportamento esistente — i test attuali di `TagReplacer`
  restano la guardia di non-regressione.

## Fuori scope (questo spec)

- Viewer PDF nell'admin (rendering pagine, pdf.js).
- Cattura click/trascinamento e conversione pixel del viewport → punti PDF
  (incluso il flip dell'origine, dall'alto-sinistra dello schermo al
  basso-sinistra nativo PDF).
- UI/UX del flusso "upload → avviso tag mancante → apertura builder →
  salvataggio".
- Tutto quanto sopra è **parte 2**, spec separato futuro.
- Supporto ad alberi `/Pages` annidati, pagine ruotate, `/Resources`
  indirette: rifiutati esplicitamente, non implementati in questa fase.
