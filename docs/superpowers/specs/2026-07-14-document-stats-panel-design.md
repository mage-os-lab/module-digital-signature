# Pannello metriche sulla griglia documenti firma

Data: 2026-07-14

## Contesto

Sessione di roleplay "merchant che valuta il modulo" (2026-07-14): oggi la
griglia admin documenti (`Controller/Adminhtml/Document/Index`) mostra solo
i singoli record, nessuna vista aggregata (totale generati, firmati, tempo
medio di firma). Un merchant con volumi reali vorrebbe vedere questi numeri
senza contarli a mano riga per riga.

**Buona notizia trovata esplorando lo schema**: la tabella
`mageos_digitalsignature_document_log` registra già ogni transizione di
stato (`event=status_change`, `status_from`/`status_to`, timestamp preciso)
— i dati per calcolare metriche come "tempo medio di firma" **esistono
già**, nessuna nuova colonna necessaria sul documento.

## Decisioni chiave

- **Nessuna pagina/menu/ACL nuovi**: le metriche compaiono come riga di tile
  riepilogative **sopra la griglia documenti esistente** — stesso posto già
  visitato dal merchant.
- **Calcolo live, nessuna tabella di rollup precalcolata da cron**: query di
  aggregazione dirette ad ogni caricamento pagina. Per i volumi tipici di un
  e-commerce non serve la complessità di un rollup periodico, e i numeri
  sono sempre aggiornati in tempo reale.
- **Filtro periodo con select, fin dalla prima versione** (non rimandato):
  opzioni "Ultimi 30 giorni / Ultimi 90 giorni / Ultimo anno / Sempre",
  applicato su `document.created_at`. **Tutte le metriche condividono lo
  stesso filtro** (nessuna semantica mista tra metriche).
- **Nessuna chiamata AJAX per la v1**: il select ricarica la pagina con un
  parametro in querystring — superficie minima, coerente con l'approccio
  "minimo necessario" già seguito altrove nel modulo. Aggiornabile a
  un'esperienza senza reload in futuro, se richiesto.

## Architettura

### Metriche (5)

1. **Totale documenti generati** nel periodo selezionato.
2. **Firmati** (conteggio + percentuale sul totale del periodo).
3. **Scaduti/rifiutati** (conteggio + percentuale — somma `expired` +
   `declined`).
4. **In attesa** (conteggio, stato `sent` attivo — `is_active=1`).
5. **Tempo medio di firma** (giorni, calcolato da `document_log`: media
   della differenza tra il timestamp dell'evento `status_change` con
   `status_to='signed'` e quello con `status_to='sent'`, per lo stesso
   `document_id`, ristretto ai documenti del periodo selezionato).

Tutte e 5 calcolate sul sottoinsieme di documenti con `created_at` nel
periodo selezionato — filtro unico, coerente.

### Componenti nuovi

| Componente | Ruolo |
|---|---|
| `Model/Document/Stats/Aggregator.php` (nuovo) | Riceve un intervallo di date, esegue le query di aggregazione (COUNT per stato via `ResourceModel\Document\Collection`, AVG tempo di firma via query diretta su `document_log`), ritorna un DTO `Model/Document/Stats/Summary.php` con i 5 valori |
| `Model/Document/Stats/PeriodSource.php` (nuovo) | `OptionSourceInterface`-style: le 4 opzioni di periodo (30/90/365 giorni, sempre) → intervallo di date effettivo |
| `Block/Adminhtml/Document/StatsPanel.php` (nuovo) | Legge il periodo da querystring (default: "Sempre" o "Ultimi 30 giorni" — da confermare nel piano), invoca `Aggregator`, espone il `Summary` e le opzioni di `PeriodSource` al template |
| `view/adminhtml/templates/document/stats_panel.phtml` (nuovo) | Rendering: 5 tile + select periodo (form GET semplice, nessun JS) |
| `view/adminhtml/layout/mageos_digitalsignature_document_index.xml` (modificato) | Aggiunge il blocco `StatsPanel` sopra il blocco `Listing` esistente |

### Data flow

1. Merchant apre/ricarica la griglia documenti (opzionalmente con
   `?stats_period=90d` in querystring).
2. `StatsPanel` risolve il periodo (default se assente), chiede il
   `Summary` all'`Aggregator`.
3. `Aggregator` esegue le query aggregate ristrette a `created_at` nel
   periodo.
4. Template renderizza le 5 tile + il select (che ricarica la pagina con un
   nuovo `stats_period` alla selezione — form GET, nessun AJAX).
5. La griglia sottostante (`Listing` UI component) resta **indipendente**
   dal filtro periodo — non è nello scope far sì che i due si sincronizzino
   (la griglia ha già i propri filtri nativi).

### Gestione errori

Nessuna prevista: query di sola lettura su tabelle già esistenti, nessun
input utente oltre alla scelta tra 4 opzioni fisse (nessuna data libera da
validare).

### Testing

Unit test per `Model/Document/Stats/PeriodSource` (mapping opzione →
intervallo di date, puro, nessuna dipendenza Magento). Nessun test per
`Aggregator` (dipende da `Collection`/query dirette, stessa limitazione già
accettata per `Reconcile`/`RetentionCleanup`/il futuro `SignatureReminder`)
né per `Block/StatsPanel` (stessa convenzione già stabilita per i block
admin del modulo).

## Fuori scope

- Filtro per store view: le metriche restano aggregate su tutti gli store
  per la prima versione.
- Sincronizzazione tra il filtro periodo delle tile e i filtri nativi della
  griglia `Listing` sottostante.
- Esperienza senza reload (AJAX): il select usa un semplice GET, upgrade a
  componente reattivo rimandato a una versione futura se richiesto.
- Metriche aggiuntive oltre le 5 elencate (es. metriche per provider, per
  template, andamento nel tempo/grafico): non nello scope della prima
  versione.
