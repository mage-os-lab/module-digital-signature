# Connettori di Firma Digitale Disponibili e Pianificati

Questo documento elenca i connettori (provider) di firma digitale disponibili "di serie" nel modulo core, quelli in corso di sviluppo e le opzioni per l'integrazione di provider di terze parti.

---

## 1. Connettori Integrati nel Core

Questi provider sono distribuiti direttamente con il modulo `MageOS_DigitalSignature` e sono pronti all'uso previa configurazione:

*   **WsSign (Sponsor del Progetto)**
    *   *Tipo di Firma:* Elettronica Avanzata (AES) basata su OTP (SMS o Email).
    *   *Uso:* Produzione e Test (con ambienti dedicati).
    *   *Documentazione:* Vedi [docs/wssign-api.md](wssign-api.md).
*   **Dummy**
    *   *Tipo di Firma:* Nessuna validità legale.
    *   *Uso:* Esclusivamente test locale e sviluppo. Simula il flusso di firma (invio, callback e firma) senza contattare servizi esterni.

---

## 2. Connettori in Corso di Sviluppo (Moduli Satellite)

I nuovi connettori di firma non saranno inclusi nel pacchetto core per mantenere il modulo snello, ma verranno distribuiti come moduli satellite indipendenti:

*   **Adobe Acrobat Sign (In sviluppo)**
    *   *Stato:* Pianificato / In sviluppo.
    *   *Tipo di Firma:* Elettronica Semplice (SES) e Avanzata (AES).
    *   *Integrazione:* Autenticazione OAuth2, caricamento accordo e polling/webhook di stato.

---

## 3. Vuoi Sviluppare un Connettore Personalizzato?

Il modulo è stato progettato con un'architettura pluggable simile ai metodi di pagamento di Magento. Qualsiasi agenzia o sviluppatore terzo può implementare un connettore per un provider locale o internazionale (es. DocuSign, Namirial, InfoCert, Yousign).

Per le istruzioni dettagliate su come estendere il modulo e registrare un nuovo provider, consulta la guida:
*   [Guida all'integrazione dei provider di firma](sign-provider-integration.md).
