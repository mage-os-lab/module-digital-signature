# Reminder automatici per documenti fermi in firma

Data: 2026-07-14

## Contesto

Sessione di roleplay "merchant che valuta il modulo" (2026-07-14): oggi non
esiste alcun sollecito automatico quando un documento resta fermo nello
stato "inviato in firma" (`SENT`) — il merchant deve controllare la griglia
admin manualmente. Esiste già un cron `Cron\Reconcile` (ogni 5 minuti) che
**ri-sincronizza lo stato** con il provider per documenti fermi (richiama
`fetchStatus`, copre callback perse), ma è puramente operativo: nessuna
comunicazione verso cliente o admin.

Nota rilevante trovata nel modulo: l'email "documento pronto per la firma"
verso il cliente è **disattivata di default**, perché "il provider stesso
spesso invia già un invito a firmare" (`docs/analisi.md`, notifiche
email). Un reminder Magento-side è quindi un **fallback**, non l'unico
meccanismo di sollecito — utile soprattutto per provider senza un proprio
sistema di reminder nativo.

## Decisioni chiave

- **Due destinatari indipendenti, ciascuno col proprio flag
  enable/disable**: sollecito al cliente ("non hai ancora firmato") ed
  escalation all'admin ("documento fermo da N giorni, verifica") — stesso
  principio già usato per le altre notifiche del modulo (ogni tipo email
  ha il proprio toggle in configurazione).
- **Reminder cliente ripetuti** (fino a un massimo configurabile,
  intervallo configurabile tra un sollecito e l'altro) — non un singolo
  invio una tantum.
- **Escalation admin singola** (non ripetuta): il documento è già visibile
  in ogni momento nella griglia admin, non c'è valore nel rispedire più
  email di avviso per lo stesso documento.
- **Default**: reminder cliente **disattivato** (stesso principio opt-in
  delle altre email cliente, per non essere ridondante col provider),
  escalation admin **attivata** (nessun rischio di spam per una
  comunicazione interna operativa).
- **Soglia escalation admin tipicamente più lunga** di quella del reminder
  cliente: prima si sollecita il cliente, solo se non basta si allerta
  l'admin.

## Architettura

### Schema

Nuove colonne su `document` (stesso pattern già usato per `purged_at`,
introdotta per la retention):
- `reminder_count` (smallint, default 0) — contatore solleciti cliente
  inviati.
- `last_reminder_at` (datetime, nullable) — timestamp ultimo sollecito
  cliente.
- `escalation_sent_at` (datetime, nullable) — timestamp escalation admin
  (presenza = già inviata, non si ripete).

### Configurazione

Nuovo gruppo `digital_signature/reminders/*`:

| Campo | Default | Ruolo |
|---|---|---|
| `customer_enabled` | No | Abilita il reminder cliente |
| `customer_threshold_days` | (da definire in fase di piano, es. 3) | Giorni da `SENT` prima del primo reminder |
| `customer_interval_days` | (es. 3) | Giorni tra un reminder e il successivo |
| `customer_max_reminders` | (es. 2) | Numero massimo di reminder cliente |
| `admin_enabled` | Sì | Abilita l'escalation admin |
| `admin_threshold_days` | (es. 7, più lungo della soglia cliente) | Giorni da `SENT` prima dell'escalation |
| `batch_limit` | stesso default di `Reconcile`/`RetentionCleanup` | Throttling per esecuzione cron |

### Componenti nuovi

| Componente | Ruolo |
|---|---|
| `Model/Reminder/EligibilityCalculator` (nuovo) | Logica pura: dati giorni trascorsi da `SENT`, `reminder_count`, `last_reminder_at`, soglie/intervalli/massimi configurati → ritorna se il documento è idoneo a reminder cliente e/o escalation admin. **Unico componente testato** di questa feature |
| `Cron\SignatureReminder` (nuovo) | Giornaliero (stesso schedule di `Cron\RetentionCleanup`). Interroga documenti `status=SENT`, `is_active=1`; per ciascuno usa `EligibilityCalculator` per decidere reminder/escalation; invia via `Notifier`, aggiorna `reminder_count`/`last_reminder_at`/`escalation_sent_at` |
| `Model/Notification/Notifier.php` (modificato) | Due nuovi metodi: `notifyDocumentReminder(DocumentInterface $document)`, `notifyDocumentEscalation(DocumentInterface $document)` |
| `etc/email_templates.xml` (modificato) | Due nuovi template (area frontend, stesso motivo già documentato per gli altri: evitare problemi di area da consumer/cron) |
| `etc/adminhtml/system.xml` (modificato) | Nuovo gruppo `reminders` (tabella sopra) |
| `db_schema.xml` / whitelist (modificati) | Le tre nuove colonne su `document` |

### Data flow

1. Documento transita a `SENT` (invariato, pipeline esistente).
2. Ogni giorno, `Cron\SignatureReminder`:
   - Per ogni documento `SENT`/`is_active=1`: calcola giorni trascorsi da
     `updated_at` (stessa semantica di "staleness" già usata da
     `Cron\Reconcile`, nessuna nuova colonna "sent_at" dedicata).
   - `EligibilityCalculator` decide: reminder cliente dovuto? Escalation
     admin dovuta?
   - Se sì (e il relativo `*_enabled`=Sì): invia via `Notifier`, aggiorna i
     contatori/timestamp.
3. Se il documento esce da `SENT` (firmato/rifiutato/scaduto/annullato/
   errore), il cron smette semplicemente di selezionarlo — nessuna pulizia
   esplicita necessaria dei contatori.
4. Se il documento viene rigenerato/reinviato (nuovo record attivo,
   pattern già esistente per la sostituzione), il nuovo record parte con
   `reminder_count=0`/`last_reminder_at=NULL`/`escalation_sent_at=NULL` —
   comportamento naturale, nessuna gestione speciale.

### Gestione errori

Un fallimento di invio email per un singolo documento non deve bloccare gli
altri nello stesso batch — stesso principio già seguito da `Notifier`
("invii robusti try/catch+log, mai bloccanti").

### Testing

- `EligibilityCalculator`: soglia non ancora raggiunta, soglia raggiunta la
  prima volta, già al numero massimo di reminder cliente, intervallo tra
  reminder non ancora trascorso, escalation admin già inviata (non si
  ripete), soglie cliente/admin valutate indipendentemente (un documento
  può essere idoneo a uno solo dei due, a entrambi, o a nessuno).
- Nessun test automatico per `Cron\SignatureReminder` stesso: stessa scelta
  già presa per `Cron\Reconcile`/`Cron\RetentionCleanup` (dipendenza da
  `CollectionFactory` non mockabile nell'harness standalone del modulo).

## Fuori scope

- Un meccanismo che chieda al **provider** di re-inviare il proprio invito
  nativo (estensione di `SignProviderInterface` con un metodo tipo
  `remind()`): non nello scope — il reminder qui è interamente
  Magento-side, il comportamento nativo di ciascun provider resta fuori dal
  controllo del modulo.
- Valori esatti di soglia/intervallo/massimo default: indicati come esempi,
  i valori definitivi vanno decisi in fase di piano di implementazione.
- Reminder per stati diversi da `SENT` (es. `PENDING`/`GENERATED`, stati di
  elaborazione interna già coperti da `Cron\Reconcile` con retry, non da
  una comunicazione verso cliente/admin).
