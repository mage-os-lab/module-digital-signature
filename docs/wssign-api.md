# API WsSign — riferimento estratto dal progetto iscrizioni.sthdev04.myvdc.it

> Fonte: `app/Services/WsignService.php`, `app/Controllers/WsignController.php`,
> `app/Model/Richieste.php` (flusso reale in produzione). Data estrazione: 2026-06-12.

## Configurazione richiesta

| Parametro | Esempio | Note |
|---|---|---|
| `WSIGN_PLATFORM` | `https://demo.wsign.cloud` | base URL (demo o produzione) |
| `WSIGN_USER` | email account owner | usato come `owner` dei documenti e come username OAuth |
| `WSIGN_PASS` | password | |
| `WSIGN_TENANT` | codice tenant | parte dell'URL del token |
| `WSIGN_ALLOWED_IPS` | lista IP separati da virgola | allowlist per la callback (opzionale) |

## Autenticazione — OAuth2 password grant

```
POST {platform}/api/token/{tenant}
Content-Type: application/x-www-form-urlencoded

grant_type=password&client_id=wsign-api&username={user}&password={pass}
```

Risposta: JSON con `access_token` (Bearer per tutte le chiamate successive).

## Upload documento

```
POST {platform}/api/v4/consumer/document
Authorization: Bearer {token}
Content-Type: application/json

{
  "owner": "{WSIGN_USER}",
  "files": [ { "name": "documento.pdf", "file": "<PDF in base64>" } ]
}
```

Risposta attesa: `message == "DOCUMENT_ADDED"`, GUID in
`data.documents[0].guid`. Il GUID è l'identificativo del processo
(→ `provider_process_id` nella nostra entità documento).

## Avvio firma (share)

```
POST {platform}/api/v6/consumer/document/{guid}/share
Authorization: Bearer {token}
Content-Type: application/json

{
  "receivers": [{
    "email": "firmatario@example.com",
    "phonePrefix": "+39",
    "phone": "3331234567",
    "minSignatures": 1,
    "channel": "EMAIL",
    "locale": "it"
  }],
  "notifiers": [{ "email": "admin@example.com", "locale": "it" }],
  "notes": "Ti chiediamo di firmare digitalmente il documento",
  "expirationDate": "2026-06-19",
  "signatureType": "OTP_SMS",
  "notifyOwner": false,
  "locale": "it",
  "callBackUrl": "https://shop.example.com/.../callback/{guid}",
  "redirectUrl": "https://shop.example.com/.../redirect/{guid}"
}
```

Risposta: JSON con campo `message`. **Nota**: con `signatureType: OTP_SMS`
il numero di **cellulare del firmatario è obbligatorio** (OTP via SMS).
Il numero va normalizzato (in iscrizioni: libphonenumber, default IT).
L'email dell'owner non può essere tra i receivers (va esclusa).

## Callback (server-to-server)

- WsSign chiama `callBackUrl` (in iscrizioni registrata sia POST che GET).
- Payload JSON: GUID in `guid` oppure `data.guid`. Nel flusso iscrizioni la
  callback viene trattata come "documento firmato": si scarica il PDF firmato
  e in caso di errore si logga.
- **Nessuna firma/HMAC sul payload**: la sicurezza è solo allowlist IP.
  Nel nostro modulo: aggiungere anche un token segreto nell'URL di callback.
- Rispondere sempre HTTP 200 (anche su errori interni) per evitare retry storm;
  400 solo se manca il GUID.

## Download PDF firmato

```
GET {platform}/api/v2/consumer/document/{guid}/download
Authorization: Bearer {token}
Accept: application/pdf
```

Risposta: corpo binario PDF (HTTP 200).

## Cancellazione documento

```
DELETE {platform}/api/v2/consumer/document/{guid}
Authorization: Bearer {token}
```

Considerare riuscita con HTTP 2xx **o 404** (già rimosso). Usata in iscrizioni
dopo il download del firmato (pulizia) e nel flusso di reinvio/sostituzione.

## Tag firma nel PDF

Formato: `{WSIGN#<larghezza>,<altezza>#<email-firmatario>}` (dimensioni in mm,
es. `{WSIGN#80,20#mario.rossi@gmail.com}`). Il tag è **testo dentro il PDF**:
WsSign lo individua e posiziona lì il campo firma per il firmatario con quella
email. In iscrizioni il PDF viene generato già con l'email reale; nel nostro
modulo invece il template PDF del merchant contiene un'email placeholder da
sostituire con quella del cliente (vedi `docs/analisi.md`).

## Dettaglio/polling stato documento

```
GET {platform}/api/v2/consumer/document/{guid}
Authorization: Bearer {token}
```

Risposta: `data.status` (stato complessivo del documento) e
`data.receivers[].status` (stato per singolo firmatario). **HTTP 404** =
documento scaduto o cancellato lato WsSign.
Fonte: `bin/debug-wsign-reinvia.php` in iscrizioni.

I valori esatti dell'enum di stato **non sono noti** (nessuna documentazione
ufficiale disponibile, nemmeno online): vanno rilevati empiricamente. La
mappatura configurabile stati provider → stati interni prevista in
`docs/analisi.md` assorbe questa incognita: gli stati sconosciuti ricevuti
vengono loggati e l'admin li mappa via via.

## Lista documenti (paginata)

```
GET {platform}/api/v2/consumer/document?page=1&pageSize=100
Authorization: Bearer {token}
```

Utile per cron di riconciliazione. Fonte: `bin/wsign-delete-all.php`.

## Comportamento reale della callback

Dal log `wsign_callback.log` di iscrizioni: il body della callback può essere
**vuoto (`{}`)** — il GUID arriva solo nell'**URL** (per questo iscrizioni
registra la route `/callback/{guid}`). La callback va quindi trattata come un
**ping**: alla ricezione si interroga `GET /document/{guid}` per conoscere lo
stato effettivo, non ci si fida del payload.

## Punti aperti verso WsSign (non risolvibili dal codice)

- **Nessuna documentazione ufficiale online**: ogni informazione va ricavata
  dal codice di iscrizioni o testata sull'ambiente demo.
- `signatureType` alternativi a `OTP_SMS` (es. senza SMS): sconosciuti. Finché
  non emergono, il telefono del firmatario è obbligatorio.
- Valori enum di `data.status` e `receivers[].status`: da rilevare in demo.
