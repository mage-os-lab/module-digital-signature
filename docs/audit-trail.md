# Audit trail (eIDAS)

The audit trail keeps evidence of each signature beyond the signed PDF: a SHA-256 hash of the
document before and after signing, and the provider's official signing certificate with the
signer details it exposes. It is meant to support the merchant in proving a signature in a
dispute. It is not a compliance guarantee from the module: the legal value of a signature
depends on the provider and on the signature level it issues.

## The compliance choice (required)

Collecting audit trail data means keeping personal data (signer IP, authentication details,
certificates that name the signer) for a legal purpose, usually for longer than the document
itself. The merchant is the data controller, so the module does not choose for them:
**no signature document is generated until the choice is made explicitly**.

Set it under **Stores > Configuration > Mage-OS > Digital Signature > Audit trail (eIDAS
compliance)**, or from the command line:

```bash
# Opt out: no extra data is collected, documents are generated as usual
bin/magento config:set digital_signature/audit_trail/data_collection 0

# Opt in: also requires the policy acknowledgement and a retention period
bin/magento config:set digital_signature/audit_trail/data_collection 1
bin/magento config:set digital_signature/audit_trail/policy_ack 1
bin/magento config:set digital_signature/audit_trail/retention_days 3650
```

| Path | Default | Description |
|---|---|---|
| `digital_signature/audit_trail/data_collection` | *(unset)* | `1` collect, `0` do not collect. While unset, document generation is blocked |
| `digital_signature/audit_trail/policy_ack` | `0` | Confirms that the legal basis and retention period have been defined. Required when collecting |
| `digital_signature/audit_trail/retention_days` | `3650` | Days after the certificate fetch before its personal data is purged. Required (> 0) when collecting |

The choice is valid when `data_collection` is `0`, or when it is `1` with `policy_ack` set to
`1` and `retention_days` greater than 0. Until then:

- automatic triggers (`order_placed`, `invoice_created`, `invoice_paid`) create no document and
  log the reason in `var/log/system.log`; the checkout is not affected;
- manual generation from the admin order grid or the REST API returns the error *"Digital
  Signature is enabled but the audit trail compliance choice has not been configured for store
  …"*;
- a critical notice in the admin notification bar links to the setting, as long as at least
  one store with Digital Signature enabled has no valid choice.

The fields are shown at default (global) scope in the admin.

## What is collected

When `data_collection` is `1`, one row per document is kept in
`mageos_digitalsignature_document_audit_trail`:

| Column | Content | Purged by retention |
|---|---|---|
| `hash_before_signing` | SHA-256 of the PDF sent to the provider | No |
| `hash_after_signing` | SHA-256 of the signed PDF downloaded from the provider | No |
| `transaction_id` | Provider envelope/agreement id | No |
| `certificate_path` | Stored copy of the provider certificate (PDF) | Yes, file deleted |
| `signer_ip` | Signer IP, for connectors that expose it as data (the built-in providers print it in the certificate instead) | Yes |
| `authentication_method` | Signer authentication method (reserved: no built-in provider fills it yet) | Yes |
| `fetch_status` | `pending`, `fetched`, `unavailable` or `error` | No |

The hashes are computed by the module for every provider. Compare `hash_after_signing` with
the SHA-256 of a signed PDF to check it is the file the module received:

```bash
sha256sum signed.pdf
```

Certificates are fetched only from providers that expose one:

| Provider | Certificate |
|---|---|
| DocuSign | Certificate of Completion (the signer IP is inside the certificate, not in a column) |
| Adobe Acrobat Sign | Audit Trail Report (the signer IP is inside the report, not in a column) |
| wSign | Not available through the API: hashes only (`fetch_status` = `unavailable`) |
| Dummy | None (`unavailable`) |

## How the certificate is fetched

1. The `digitalsignature_reconcile` cron (every 5 minutes) selects `signed` documents that have
   been idle for 10 minutes and have no certificate yet, or whose previous fetch failed less
   than `digital_signature/general/max_retries` times. Documents already purged by the
   document retention are skipped.
2. It publishes them to the `digitalsignature.audit_trail.fetch` topic.
3. The `digitalsignatureAuditTrailFetch` consumer downloads the certificate and stores it next
   to the document PDFs (`order_<orderId>/document_<documentId>_certificate.pdf`).

A temporary provider error leaves the row in `error` and the next cron run retries it. A
permanent error (for example a document the provider no longer knows) is not retried; the
reason is in `last_error`.

Run the consumer like the others if your store does not start consumers from cron:

```bash
bin/magento queue:consumers:start digitalsignatureAuditTrailFetch &
```

## Download the certificate

In **Digital Signature > Signature documents**, the *Actions* column shows **Download
certificate** for documents whose certificate has been fetched and not yet purged. It requires
the *Signature documents* ACL resource.

## Export a dossier

To hand evidence to a lawyer or an auditor, select documents in **Digital Signature >
Signature documents** and choose **Export dossier (ZIP)** from the mass actions. The ZIP
contains, for each document:

```
document-42/signed.pdf          # or document.pdf if it was never signed
document-42/certificate.pdf     # when a certificate is available
manifest.json
```

`manifest.json` records who generated the dossier and when, and for each document its order,
status, provider, hashes and transaction id, plus why a file is missing:

```json
{
  "generated_at": "2026-10-14T09:30:00+00:00",
  "generated_by": "admin",
  "documents": [
    {
      "document_id": 42,
      "order_id": 1001,
      "status": "signed",
      "provider_code": "docusign",
      "hash_before_signing": "9f86d0…",
      "hash_after_signing": "2c26b4…",
      "transaction_id": "a1b2c3d4-…",
      "pdf_included": true,
      "certificate_included": false,
      "pdf_unavailable_reason": null,
      "certificate_unavailable_reason": "fetch_pending"
    }
  ]
}
```

| Reason | Meaning |
|---|---|
| `not_generated` | The document never had a PDF |
| `storage_read_failed` | The file is recorded but cannot be read (deleted by retention, or a storage error) |
| `audit_trail_disabled` | No audit trail row: data collection was off when the document was processed |
| `fetch_pending` | The certificate has not been fetched yet |
| `fetch_error` | The fetch failed; see `last_error` in the audit trail table |
| `provider_not_supported` | The provider has no certificate (wSign, Dummy) |
| `purged_by_retention` | The certificate was fetched, then deleted by the audit trail retention |
| `document_not_found`, `resolution_failed` | The selected document could not be loaded |

Exporting requires the *Export document dossier (ZIP)* ACL resource
(`MageOS_DigitalSignature::document_dossier_export`). What happens depends on how many
documents you select:

- **Up to `sync_threshold`** (default 20): the ZIP downloads immediately. It is written to a
  temporary file under `var/digitalsignature/dossiers/` and removed as soon as it is sent.
- **Above it**: the export is queued. You get the message *"Dossier export #12 queued"*, the
  `digitalsignatureDossierExport` consumer builds the ZIP, and a notification in the admin bell
  tells you when it is ready (or why it failed).
- **Above `max_documents`** (default 500): the export is refused. Narrow the selection.

Queued exports are listed in **Digital Signature > Dossier exports**, visible to every admin
with the same ACL resource: status, requester, documents and how many have been processed,
size, dates and the error of a failed export. **Download** is available for ready exports;
**Delete** removes the export and its ZIP immediately. The ZIP contains personal data, so it is
deleted automatically `retention_hours` (default 72) after it is ready.

| Path | Default | Description |
|---|---|---|
| `digital_signature/dossier_export/max_documents` | `500` | Larger selections are refused |
| `digital_signature/dossier_export/sync_threshold` | `20` | Up to this number the ZIP downloads immediately; `0` = always queued |
| `digital_signature/dossier_export/retention_hours` | `72` | Hours a ready ZIP is kept |

Run the consumer like the others if your store does not start consumers from cron:

```bash
bin/magento queue:consumers:start digitalsignatureDossierExport &
```

The hourly `digitalsignature_dossier_export_cleanup` cron deletes expired ZIPs, marks as failed
an export that has made no progress for 15 minutes (consumer stopped or killed: *"Interrupted:
the export stopped making progress."*), and removes failed and expired rows after 30 days. An
export that is slow but alive reports progress after every document and is never interrupted.

## Retention

The audit trail has its own retention, separate from the document retention described in
[triggers-and-configuration.md](triggers-and-configuration.md#retention-gdpr): proof of
signature is usually kept for the contract's limitation period, which can differ from how long
the PDF and the signer's contact details are needed.

The `digitalsignature_retention_cleanup` cron (03:00 daily) deletes the stored certificate and
clears `signer_ip` and `authentication_method` once `retention_days` have passed since the
certificate was fetched. The hashes, the transaction id and the fetch status are kept: they do
not identify the signer and remain the integrity evidence.

This purge always runs, whether or not the document retention
(`digital_signature/retention/enabled`) is enabled, and processes up to
`digital_signature/retention/batch_limit` rows per run.

## Providers: exposing a certificate

A provider connector can offer a certificate by also implementing
`MageOS\DigitalSignature\Api\AuditTrailCertificateProviderInterface`. See
[sign-provider-integration.md](sign-provider-integration.md#10-optional-audit-trail-certificate).
