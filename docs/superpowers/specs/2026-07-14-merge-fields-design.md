# Merge field dinamici nel template PDF

Data: 2026-07-14

## Contesto

Sessione di roleplay "merchant che valuta il modulo" (2026-07-14): oggi il
solo dato dinamico sostituito nel PDF template è l'email del firmatario
(`{WSIGN#W,H#email}`, gestito da `TagReplacer`). Un merchant si aspetta di
poter inserire nel contratto anche altri dati dell'ordine (numero ordine,
importo, nome cliente, date), come farebbe un motore di "mail merge".

**Campi per la prima release** (decisi in sessione): numero ordine, importo
totale, nome cliente, data ordine, data fattura.

**Requisito di estensibilità** (esplicito dell'utente): uno sviluppatore
terzo deve poter aggiungere facilmente altri campi (indirizzo, altri dati
ordine, ecc.) **via `di.xml`**, senza modificare il modulo core — stesso
principio già applicato ai provider di firma
(`docs/sign-provider-integration.md`).

## Decisioni chiave

- **Stesso pattern pluggable di `SignProviderInterface`**: interfaccia
  comune + pool registrato via merge di `di.xml`. I 5 campi della prima
  release sono provider core registrati nel `di.xml` del modulo esattamente
  come oggi `wssign`/`dummy`; un modulo satellite terzo aggiunge i propri
  con un `<item>` in più sullo stesso nodo, zero modifiche al core.
- **Sintassi tag distinta**: `{FIELD:codice}` (es. `{FIELD:grand_total}`,
  `{FIELD:order_number}`) — famiglia separata da `{WSIGN#W,H#email}`, non
  riusa lo stesso regex. Il codice campo è un identificatore semplice
  (`[a-z0-9_]+`), non un'espressione o un path libero: stessa scelta già
  fatta per l'email, minima superficie di parsing/injection.
- **Data fattura non sempre disponibile** (il documento può essere generato
  al trigger "conferma ordine", prima che esista una fattura). Deciso in
  sessione: **prevenzione a monte, non gestione a runtime** (Opzione C) — un
  template che usa un campo non disponibile per il trigger configurato non
  può essere salvato. Nessun documento incompleto viene mai generato in
  produzione.
- **Formattazione locale per store**: ogni provider di campo formatta da
  sé il proprio valore (valuta per l'importo, formato data locale) — niente
  motore di formattazione centralizzato per la prima release.
- **Un solo passaggio di scrittura**: la sostituzione dei merge field e
  quella dell'email firmatario avvengono nella **stessa** incremental
  update (una sola nuova revisione xref), riusando l'`IncrementalUpdateWriter`
  condiviso introdotto in
  `docs/superpowers/specs/2026-07-14-signature-tag-injector-design.md`.

## Architettura

### Componenti nuovi

| Componente | Ruolo |
|---|---|
| `Api/MergeFieldProviderInterface` | `getCode(): string`; `resolve(MergeFieldContext $context): string` (valore già formattato); `isAvailableForTrigger(string $triggerCode): bool` |
| `Api/MergeFieldPoolInterface` + `Model/MergeField/Pool` | Stesso schema di `SignProviderPoolInterface`/`Pool`: valida a runtime che ogni elemento iniettato implementi l'interfaccia, indicizzato per codice |
| `Model/MergeField/Context` | DTO: ordine, fattura (nullable), store — passato a `resolve()` |
| `Model/MergeField/OrderNumber.php`, `GrandTotal.php`, `CustomerName.php`, `OrderDate.php`, `InvoiceDate.php` | I 5 provider core. `InvoiceDate::isAvailableForTrigger()` ritorna `false` per il trigger "conferma ordine" (unico caso realmente incompatibile tra i 5 campi e i trigger esistenti) |
| `Model/Pdf/MergeFieldReplacer` (o estensione di `TagReplacer`) | Scansione dei tag `{FIELD:codice}` nel PDF (stesso stile di estrazione oggetti di `TagReplacer::extractStreamObjects`), risoluzione tramite il pool, sostituzione nello stesso passaggio di `replaceSignerEmail()` |
| `Model/Pdf/PreviewOrderContext` (nuovo) | Contesto ordine "di esempio" a valori fissi (numero fittizio, importo, nome, date), usato da `TemplateValidator`/`Preview` per il dry-run, analogo a `TemplateValidator::PREVIEW_SIGNER_EMAIL` ma esteso ai nuovi campi |
| `Controller/Adminhtml/Template/Save` (modificato) | Al salvataggio: scansiona i tag `{FIELD:...}` presenti nel PDF corrente, verifica `isAvailableForTrigger()` per ognuno contro il trigger effettivo del template (specifico o default globale se non sovrascritto). Fallisce con errore esplicito se un campo non è compatibile |

### Data flow

**Salvataggio template** (validazione a monte, Opzione C):
1. Merchant salva il template (PDF già caricato, `trigger_code` noto nello
   stesso form).
2. Si estraggono i codici `{FIELD:...}` presenti nel PDF.
3. Per ciascuno, `isAvailableForTrigger($triggerEffettivo)`: se anche uno
   solo ritorna `false` → salvataggio rifiutato, messaggio che indica quale
   campo e quale trigger sono incompatibili.
4. Solo se tutti compatibili, il salvataggio procede come oggi.

**Generazione documento** (produzione):
1. Stesso trigger pipeline esistente (`TriggerHandler`/`DocumentProcessor`).
2. Nuovo passaggio: costruzione di `MergeField\Context` da ordine (+
   fattura, se disponibile per quel trigger).
3. `TagReplacer`/`MergeFieldReplacer` sostituiscono **nello stesso
   passaggio** sia l'email firmatario sia tutti i `{FIELD:...}` trovati →
   una sola nuova revisione incrementale.

**Dry-run/anteprima** (upload + pulsante anteprima):
1. `PreviewOrderContext` fornisce dati fittizi per tutti i campi core.
2. Stesso identico path di scrittura della produzione, email e campi
   fittizi, risultato scartato (validazione) o scaricato (anteprima) —
   invariato rispetto a oggi, solo esteso ai nuovi campi.

### Gestione errori

- Campo non riconosciuto (codice non registrato nel pool) → stesso
  trattamento di un tag firma malformato: errore esplicito, mai sostituzione
  silenziosa con stringa vuota.
- Campo riconosciuto ma incompatibile col trigger → bloccato al
  **salvataggio** (Opzione C), non a runtime.
- Fattura assente per un campo che la richiede, nonostante la validazione a
  monte → non dovrebbe accadere per costruzione (l'Opzione C lo previene);
  se accadesse comunque (es. cambio di trigger successivo al salvataggio
  senza rivalidazione — vedi "Domande aperte" sotto), il comportamento resta
  da definire nel piano di implementazione.

### Testing

Unit test puri (PHP, nessuna dipendenza Magento), stesso stile del resto del
modulo:
- Ogni provider core: formattazione corretta (valuta, data) con dati di
  esempio.
- `Pool`: stesso schema di test già esistente per `Model/Provider/Pool`.
- Validazione trigger-compatibilità al salvataggio: casi compatibili e
  incompatibili per ciascuno dei 5 campi core.
- Round-trip completo: tag `{FIELD:...}` inserito in un PDF di test →
  trovato → sostituito con dato reale da un ordine/fattura mock.
- Regressione: sostituzione dell'email firmatario invariata dopo
  l'integrazione nello stesso passaggio di scrittura.

## Domande aperte per il piano di implementazione

- **Cambio di trigger dopo il salvataggio**: se un template con campo
  `invoice_date` viene salvato quando il trigger è "fattura pagata"
  (compatibile), e poi il trigger globale di default cambia (il template
  non ne aveva uno specifico, seguiva il default), la validazione fatta al
  salvataggio del template non si riattiva automaticamente. Da decidere nel
  piano: rivalidare anche quando cambia il default globale, o accettare che
  sia responsabilità del merchant risalvare il template dopo un cambio di
  trigger globale.
- **Override di trigger a livello prodotto** (per template scope=prodotto):
  la validazione userà il trigger effettivo per singolo prodotto, o solo
  quello di default del template? Da chiarire nel piano, coerente con la
  "domanda ancora aperta" già presente in `project-architecture` sul
  rapporto tra override di trigger a livello prodotto e documenti di scope
  carrello.

## Fuori scope

- Campi oltre i 5 della prima release (indirizzo, altri dati ordine): non
  implementati ora, ma l'architettura pluggable li rende aggiungibili senza
  toccare il core — sia da un satellite terzo sia in una fase futura del
  modulo core stesso.
- Un motore di formattazione centralizzato/configurabile: ogni provider
  formatta da sé, nessuna configurazione admin del formato per la prima
  release.
- Liste di lunghezza variabile (es. elenco prodotti dell'ordine): fuori
  scope, i 5 campi sono tutti valori singoli, non collezioni.
