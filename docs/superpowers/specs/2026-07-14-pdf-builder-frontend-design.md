# Builder visuale PDF nell'admin (frontend)

Data: 2026-07-14

## Contesto

Parte 2 del builder visuale per il posizionamento del tag firma, deciso
durante il roleplay merchant di questa sessione (2026-07-14). La parte 1
(backend — `PageTreeResolver` + `SignatureTagInjector`, capaci di inserire
il tag `{WSIGN#W,H#email}` in coordinate precise di una pagina) è già
specificata in
`docs/superpowers/specs/2026-07-14-signature-tag-injector-design.md` e non
viene ridiscussa qui: questo spec copre solo il **viewer PDF nell'admin, la
cattura del click/trascinamento, la conversione delle coordinate e
l'integrazione col flusso di upload esistente**.

**Decisione di flusso** (dalla sessione precedente, "Opzione 2"): il builder
non introduce uno stato "bozza" persistito — interviene **dentro** il passo
di upload esistente, sul file temporaneo, prima che il file venga allegato
al form. Se il merchant ha già preparato un PDF con il tag (scritto a mano
in un editor esterno), il flusso resta identico a oggi e il builder non
compare mai.

## Decisioni chiave

- **pdf.js come asset statico locale, versione pinnata** (default) —
  nessuna dipendenza di rete per il caso comune. **CDN come opzione
  configurabile**, con una policy CSP dinamica contribuita solo quando
  attiva (mai una voce CSP "morta" quando si usa l'asset locale, che non ne
  ha bisogno).
- **Nessuna gestione della rotazione pagina lato client**: il backend
  rifiuta comunque pagine con `/Rotate != 0` (deciso nello spec backend),
  quindi la conversione pixel→punti PDF può ignorare la rotazione.
- **Il conteggio pagine viene da pdf.js stesso** (`numPages`, disponibile
  non appena il PDF è caricato lato client) — nessuna chiamata backend
  dedicata solo per sapere quante pagine ha il documento.
- **Test JS solo sulla logica pura di conversione coordinate**, isolata in
  un modulo senza dipendenze (nessun import di knockout/jQuery/pdf.js), con
  **`node:test`** (nativo in Node, zero pacchetti npm aggiuntivi) — stessa
  filosofia "standalone, nessuna dipendenza pesante" già seguita per
  PHPUnit. Nessun test per il componente KO/DOM/rete, coerente con la
  convenzione già stabilita per gli altri componenti admin del modulo.

## Architettura

### Sorgente pdf.js: locale/CDN + CSP

- `etc/adminhtml/system.xml` (modificato): nuovo campo select
  `digital_signature/general/pdfjs_source` (**Locale** default / **CDN**) e
  campo testo `pdfjs_cdn_url` (visibile solo se `pdfjs_source=cdn`,
  precompilato con l'URL ufficiale versione pinnata, modificabile).
- `Model/Csp/PdfJsPolicyCollector implements Magento\Csp\Api\PolicyCollectorInterface`,
  registrato sul collector CSP composito dell'area adminhtml: se la
  sorgente configurata è CDN, contribuisce una `FetchPolicy` dinamica
  (`script-src` + `worker-src`, pdf.js usa un web worker) per l'host
  configurato; se è Locale, non contribuisce nulla (stessa origine, già
  coperta da `'self'`).
- `view/adminhtml/web/js/lib/pdfjs/` — asset locale (versione pinnata),
  usato quando `pdfjs_source=local`.

### Flusso di upload con builder

Modifica a `Controller/Adminhtml/Template/Upload.php`: oggi qualunque
fallimento di `TemplateValidator::validate()` cancella subito il file
temporaneo (`$this->fileDriver->deleteFile($absolutePath)`). Va introdotta
una distinzione:

- **Fallimento per "nessun tag trovato"** → il file tmp **non viene
  cancellato**; la risposta JSON include `warning: 'no_tag'` (invece della
  chiave `error` usuale) + il riferimento al file tmp già presente nella
  risposta di upload standard.
- **Ogni altro fallimento** (cifrato, object-stream, dimensione, magic
  bytes) → **invariato**: cancellazione immediata, risposta `error` come
  oggi. Il builder non interviene su questi casi (non può risolverli).

Passi lato admin, una volta ricevuto `warning: 'no_tag'`:

1. Un mixin JS sul componente `Magento_Ui/js/form/element/uploader/uploader`
   intercetta la risposta e apre `pdf-builder-modal.js` invece di mostrare
   il solito messaggio d'errore.
2. Nuovo controller `Controller/Adminhtml/Template/PreviewTmp.php` (ACL
   `MageOS_DigitalSignature::template`, stesso pattern di `Preview.php`)
   serve i byte del PDF temporaneo al browser, per il rendering pdf.js
   nella modale. Accesso solo tramite il riferimento al file tmp già
   generato dall'upload (stessa superficie di fiducia degli altri
   controller admin del modulo: sessione admin autenticata + ACL).
3. Merchant sceglie la pagina (navigazione tra `numPages` pagine renderizzate
   da pdf.js) e disegna il riquadro con mouse down/move/up sul canvas.
4. **Conversione coordinate** (modulo puro, testato — vedi sotto): dati lo
   `scale` di rendering e `viewport.width/height` (punti PDF nativi,
   riportati da pdf.js), pixel→punti PDF (`/scale`) con flip dell'origine
   (schermo alto-sinistra → PDF basso-sinistra:
   `yPdf = pageHeightPt - yPx/scale`); larghezza/altezza del riquadro
   convertite pixel→punti→mm (`mm = punti * 25.4/72`).
5. Conferma → POST a nuovo controller
   `Controller/Adminhtml/Template/InjectTag.php` (ACL `::template`) con:
   riferimento file tmp, numero pagina, coordinate x/y in punti, W/H in mm.
   Il controller invoca `SignatureTagInjector::injectAt()` (spec backend)
   sul file tmp, poi rilancia `TemplateValidator::validate()`.
6. **Successo** → risposta nello stesso formato di un upload riuscito oggi;
   il mixin la inoltra al gestore di successo standard del componente
   `fileUploader` — il file (ora con tag) viene allegato al form
   esattamente come un upload normale, nessun percorso a parte da
   mantenere lato form.
7. **Fallimento** (struttura non supportata: albero pagine annidato, pagina
   ruotata, `/Resources` indiretto — casi rifiutati esplicitamente dal
   backend) → messaggio esplicito nella modale con il motivo, builder si
   chiude, upload resta fallito. Il merchant deve comunque preparare il tag
   a mano per quel PDF, come oggi.

### Componenti nuovi

| Componente | Ruolo |
|---|---|
| `view/adminhtml/web/js/lib/pdfjs/` | Asset pdf.js locale (versione pinnata) |
| `view/adminhtml/web/js/pdf-builder-coords.js` | Modulo puro: conversioni pixel↔punti PDF↔mm, nessuna dipendenza. **Unico modulo coperto da test JS** |
| `view/adminhtml/web/js/pdf-builder-modal.js` | Componente KO/widget: viewer pdf.js, selettore pagina, cattura click/drag, chiamata a `InjectTag`, usa `pdf-builder-coords.js` per i calcoli |
| Mixin su `Magento_Ui/js/form/element/uploader/uploader` | Intercetta `warning: 'no_tag'`, apre `pdf-builder-modal.js`; su successo, inoltra al gestore standard esistente |
| `Model/Csp/PdfJsPolicyCollector.php` | Policy CSP dinamica per la sorgente CDN (sopra) |
| `Controller/Adminhtml/Template/PreviewTmp.php` | Serve i byte del PDF tmp al viewer (ACL `::template`) |
| `Controller/Adminhtml/Template/InjectTag.php` | Riceve pagina/coordinate, invoca `SignatureTagInjector`, rilancia `TemplateValidator` |
| `Controller/Adminhtml/Template/Upload.php` (modificato) | Distingue "nessun tag" (mantiene il tmp, risposta `warning`) dagli altri rifiuti (invariati) |
| `etc/adminhtml/system.xml` (modificato) | Nuovi campi `pdfjs_source`/`pdfjs_cdn_url` |
| `package.json` (nuovo, primo del repo) | `devDependencies` vuoto (solo `node:test` nativo), script `test:js` |
| `tests/js/pdf-builder-coords.test.js` (nuovo) | Test `node:test` sulla conversione coordinate |

### Testing

- **JS**: solo `pdf-builder-coords.js` — casi: click nell'angolo
  alto-sinistra/basso-destra del canvas, riquadri di varie dimensioni,
  scale diverse (zoom viewer), verifica che il flip dell'origine e la
  conversione mm siano corrette rispetto a valori calcolati a mano.
- **PHP**: nessun test nuovo oltre a quelli già previsti nello spec backend
  per `SignatureTagInjector`/`PageTreeResolver` — i controller
  `PreviewTmp`/`InjectTag`/la modifica a `Upload.php` restano senza test
  automatico, coerente con la convenzione già stabilita per i controller
  admin del modulo.
- **CI**: da aggiungere un passo `node --test` nel workflow esistente
  (`.github/workflows/ci.yml`), accanto ai passi PHP già presenti — dettaglio
  di implementazione, non di questo spec.

## Fuori scope

- Gestione di pagine ruotate o alberi `/Pages` annidati lato client: il
  backend le rifiuta esplicitamente (spec backend), il client non deve
  gestirle.
- Un secondo builder per i merge field (`{FIELD:codice}`, spec separato
  `2026-07-14-merge-fields-design.md`): questo builder posiziona solo il
  tag firma. Un'eventuale UI per inserire merge field resta fuori scope.
- Test end-to-end del flusso completo (upload → builder → salvataggio) via
  browser automation: non previsto in questo spec, eventualmente da
  valutare manualmente in fase di verifica pre-merge (come già fatto per
  altre feature UI del modulo, es. Playwright per cart/minicart/checkout).
