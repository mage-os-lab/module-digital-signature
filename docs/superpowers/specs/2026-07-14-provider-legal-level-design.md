# Livello di firma legale (eIDAS) nel box informativo provider

Data: 2026-07-14

## Contesto

Sessione di roleplay "merchant che valuta il modulo" (2026-07-14): il
modulo delega interamente la validità legale della firma al provider
esterno (scelta architetturale corretta), ma non c'è oggi un punto dove il
merchant vede **quale livello di firma** (semplice/avanzata/qualificata,
terminologia eIDAS) offre ciascun provider configurato — rischio che il
merchant assuma erroneamente una validità legale non garantita.

**Trovato in fase di analisi**: la descrizione di WsSign
(`Block/Adminhtml/System/Config/ProviderInfo/WsSign.php`) già menziona
"Piattaforma di firma elettronica avanzata (OTP via SMS o email)" — la
classificazione esiste già, ma sepolta in una frase discorsiva, non
evidenziata. Il box informativo per provider
(`AbstractProviderInfo` + `provider_info.phtml`) è il punto di innesto
naturale: è già il pattern documentato anche per sviluppatori terzi
(`docs/sign-provider-integration.md` §8).

## Decisioni chiave

- **Nuovo metodo astratto `getLegalLevel(): string`** su
  `AbstractProviderInfo` — etichetta breve, resa nel template come
  riga/badge distinto dalla descrizione discorsiva, non più sepolta nel
  testo.
- **WsSign** → *"Firma Elettronica Avanzata (AES)"* — non una nuova
  affermazione, rende solo esplicito quanto già implicito nella descrizione
  esistente (OTP-based è la classificazione AES standard sotto eIDAS,
  indipendente dal fornitore specifico).
- **Dummy** → *"Nessuna validità legale — solo test/sviluppo"* — rinforza
  l'avviso "Da NON abilitare in produzione" già presente nella descrizione.
- **Disclaimer fisso**, uguale per ogni provider, scritto una volta sola nel
  template (non per-provider): il modulo riporta la classificazione
  nota/dichiarata, non garantisce la conformità legale — importante perché
  **non esiste documentazione pubblica ufficiale WsSign** (confermato in
  sessioni precedenti), quindi il box non deve sembrare una garanzia di
  conformità da parte nostra.
- **Rottura di contratto documentata, non reale**: aggiungere un 5° metodo
  astratto obbliga ogni classe che estende `AbstractProviderInfo` a
  implementarlo. Oggi le uniche due implementazioni (`WsSign.php`,
  `Dummy.php`) sono nel core e vengono aggiornate nello stesso intervento —
  nessuna rottura effettiva. La guida per sviluppatori terzi
  (`docs/sign-provider-integration.md` §8) va comunque aggiornata da 4 a 5
  metodi, per non lasciare una documentazione disallineata che porterebbe
  un futuro integratore (agenzia esterna, o noi per Adobe Acrobat Sign) a
  scrivere una classe incompleta.

## Architettura

### Componenti modificati

| Componente | Modifica |
|---|---|
| `Block/Adminhtml/System/Config/ProviderInfo/AbstractProviderInfo.php` | Nuovo metodo astratto `getLegalLevel(): string` |
| `Block/Adminhtml/System/Config/ProviderInfo/WsSign.php` | Implementa `getLegalLevel()` → "Firma Elettronica Avanzata (AES)" |
| `Block/Adminhtml/System/Config/ProviderInfo/Dummy.php` | Implementa `getLegalLevel()` → "Nessuna validità legale — solo test/sviluppo" |
| `view/adminhtml/templates/system/config/provider_info.phtml` | Rende `getLegalLevel()` come riga/badge distinto sotto il nome provider, sopra la descrizione; aggiunge il disclaimer fisso (uguale per tutti, non letto da `getLegalLevel()`) in fondo al box |
| `docs/sign-provider-integration.md` §8 | Aggiornata la sezione "Riquadro logo/descrizione/link" da 4 a 5 metodi obbligatori, con lo stesso esempio già presente esteso al nuovo metodo |

### Testing

Nessun test automatico: coerente con la convenzione già stabilita per i
block/template admin del modulo (contenuto statico, nessuna logica da
verificare).

## Fuori scope

- Verifica/certificazione della classificazione dichiarata per provider
  futuri (es. Adobe Acrobat Sign): ogni nuovo provider dichiarerà la
  propria classificazione al momento della sua implementazione, con lo
  stesso disclaimer di non-garanzia già previsto qui.
- Un meccanismo di validazione automatica del livello di firma a runtime:
  è testo informativo statico in configurazione, non un controllo
  applicativo.
