# WsSign API — Reference Guide

> Source: Extracted from production implementation. Extraction date: 2026-06-12.

---

## WsSign Registration and Onboarding

WsSign does not offer a public self-service registration procedure on its website. To register and activate an account, follow these steps:

1. **Commercial Contact**: Request service activation through your designated account manager/commercial partner or by contacting WsSign customer support directly.
2. **Sandbox (Test) Activation**: 
   * Request WsSign support to create a **Test Tenant** on the Sandbox environment (typically hosted at `https://demo.wsign.cloud`).
   * Provide the email address you wish to set as the **Account Owner** (this person will appear as the sender/owner of documents to be signed).
   * Receive the following credentials required to configure the module from support:
     * **Tenant Code**: Alphanumeric code for your tenant.
     * **Username (owner)**: The configured owner's email address.
     * **Password**: Access password for the tenant owner.
3. **GDPR Requirements (Mandatory for Production)**:
   * Because the signing process involves transferring customer Personal Data (First Name, Last Name, Email, and Mobile Phone number for SMS OTP delivery) to third parties, a **DPA (Data Processing Agreement)** must be signed with WsSign.
   * Update your store's Privacy Policy indicating data transfer to WsSign for contract signing purposes.
4. **Production Activation**:
   * Once the DPA is signed and the commercial plan is agreed upon, request production credentials.
   * WsSign will provide the new **Tenant Code** and credentials associated with the production server (e.g. `https://wsign.vianova.it` or equivalent).
   * To unlock production dispatch in Magento/Mage-OS, remember to enable the **GDPR Acknowledgment (production mode)** checkbox under *General > GDPR Acknowledgment* in the module settings.

---

## Required Configuration

| Parameter | Example | Notes |
|---|---|---|
| `WSIGN_PLATFORM` | `https://demo.wsign.cloud` | base URL (demo or production) |
| `WSIGN_USER` | account owner email | used as document `owner` and OAuth username |
| `WSIGN_PASS` | password | |
| `WSIGN_TENANT` | tenant code | part of the token URL |
| `WSIGN_ALLOWED_IPS` | comma-separated IP list | optional callback IP allowlist |

## Authentication — OAuth2 Password Grant

```
POST {platform}/api/token/{tenant}
Content-Type: application/x-www-form-urlencoded

grant_type=password&client_id=wsign-api&username={user}&password={pass}
```

Response: JSON containing `access_token` (Bearer token for all subsequent calls).

## Document Upload

```
POST {platform}/api/v4/consumer/document
Authorization: Bearer {token}
Content-Type: application/json

{
  "owner": "{WSIGN_USER}",
  "files": [ { "name": "document.pdf", "file": "<base64-encoded PDF>" } ]
}
```

Expected response: `message == "DOCUMENT_ADDED"`, GUID in `data.documents[0].guid`. The GUID is the process identifier (→ `provider_process_id` in our document entity).

## Start Signature (Share)

```
POST {platform}/api/v6/consumer/document/{guid}/share
Authorization: Bearer {token}
Content-Type: application/json

{
  "receivers": [{
    "email": "signer@example.com",
    "phonePrefix": "+39",
    "phone": "3331234567",
    "minSignatures": 1,
    "channel": "EMAIL",
    "locale": "en"
  }],
  "notifiers": [{ "email": "admin@example.com", "locale": "en" }],
  "notes": "Please digitally sign this document",
  "expirationDate": "2026-06-19",
  "signatureType": "OTP_SMS",
  "notifyOwner": false,
  "locale": "en",
  "callBackUrl": "https://shop.example.com/.../callback/{guid}",
  "redirectUrl": "https://shop.example.com/.../redirect/{guid}"
}
```

Response: JSON with `message` field. **Note**: with `signatureType: OTP_SMS`, the **signer's mobile phone number is required** (SMS OTP). The number must be normalized (E.164 standard). The owner's email address cannot be listed in receivers.

## Callback (Server-to-Server)

- WsSign calls `callBackUrl` (handling both POST and GET).
- JSON payload: GUID in `guid` or `data.guid`. The callback is treated as a notification: the signed PDF is downloaded and errors are logged.
- **No payload HMAC signature**: security relies on IP allowlist and callback URL secret tokens.
- Always return HTTP 200 (even on internal processing errors) to avoid retry storms; return 400 only if GUID is missing.

## Download Signed PDF

```
GET {platform}/api/v2/consumer/document/{guid}/download
Authorization: Bearer {token}
Accept: application/pdf
```

Response: Binary PDF payload (HTTP 200).

## Document Cancellation

```
DELETE {platform}/api/v2/consumer/document/{guid}
Authorization: Bearer {token}
```

Consider successful on HTTP 2xx **or 404** (already removed). Used after downloading the signed document for cleanup and during resend/replacement workflows.

## Signature Tag in PDF

Format: `{WSIGN#<width>,<height>#<signer-email>}` (dimensions in mm, e.g. `{WSIGN#80,20#john.doe@example.com}`). The tag is **text embedded inside the PDF**: WsSign locates it and places the signature field there for the recipient matching that email. In our module, the merchant's PDF template contains a placeholder email that gets replaced dynamically with the customer's actual email before uploading.

## Document Detail / Status Polling

```
GET {platform}/api/v2/consumer/document/{guid}
Authorization: Bearer {token}
```

Response: `data.status` (overall document status) and `data.receivers[].status` (status per signer). **HTTP 404** = document expired or deleted on WsSign side.

Status enum values are mapped dynamically via provider status configuration to internal document statuses.

## Document List (Paginated)

```
GET {platform}/api/v2/consumer/document?page=1&pageSize=100
Authorization: Bearer {token}
```

Useful for reconciliation cron jobs.

## Callback Real Behavior

The callback payload body may be **empty (`{}`)** — the GUID is passed directly in the **URL** route (`/callback/{guid}`). The callback must therefore be treated as a **ping**: upon receipt, query `GET /document/{guid}` to inspect actual status.

## Open Notes on WsSign

- **No official online API documentation**: integration details are derived from live integration testing and sandbox environment validation.
- Alternative `signatureType` values besides `OTP_SMS`: when not using SMS OTP, signer phone numbers may be omitted depending on provider account configuration.
- Status enum values: mapped dynamically by the module status resolver.
