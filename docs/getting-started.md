# Getting started

This guide takes a Magento 2 / Mage-OS store from zero to a first signed document using the
built-in `dummy` provider, then switches to a real provider.

Module name: `MageOS_DigitalSignature` · Composer package: `mage-os/module-digital-signature`

## Requirements

- Magento Open Source 2.4.5 – 2.4.9 or Mage-OS 3.4 – 3.6
- PHP 8.1 – 8.5 with `ext-zlib`
- A running cron (`bin/magento cron:run`) and message queue consumers: generation, status
  refresh and webhooks are asynchronous

## 1. Install

The package is not on Packagist yet: register the GitHub repository with Composer before
requiring it.

```bash
composer config repositories.mage-os-digital-signature vcs https://github.com/mage-os-lab/module-digital-signature
composer require mage-os/module-digital-signature:^0.6
bin/magento module:enable MageOS_DigitalSignature
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode only
bin/magento setup:static-content:deploy  # production mode only
```

Hyvä stores also need the frontend satellite module `mage-os/module-digital-signature-hyva`
(it only replaces the storefront templates; the backend is shared).

## 2. Run the consumers

Documents are generated and sent by queue consumers. If your store does not start consumers
through cron (`cron_consumers_runner` in `app/etc/env.php`), run them explicitly:

```bash
bin/magento queue:consumers:start digitalsignatureDocumentProcess &
bin/magento queue:consumers:start digitalsignatureDocumentRefresh &
bin/magento queue:consumers:start digitalsignatureWebhookDispatch &
bin/magento queue:consumers:start digitalsignatureAuditTrailFetch &
bin/magento queue:consumers:start digitalsignatureDossierExport &
```

| Consumer | Topic | Purpose |
|---|---|---|
| `digitalsignatureDocumentProcess` | `digitalsignature.document.process` | Builds the PDF and starts the signature at the provider |
| `digitalsignatureDocumentRefresh` | `digitalsignature.document.refresh` | Fetches the provider status after a callback |
| `digitalsignatureWebhookDispatch` | `digitalsignature.webhook.dispatch` | Delivers outbound webhooks |
| `digitalsignatureAuditTrailFetch` | `digitalsignature.audit_trail.fetch` | Downloads provider certificates for the [audit trail](audit-trail.md) |
| `digitalsignatureDossierExport` | `digitalsignature.dossier.export` | Builds queued [dossier exports](audit-trail.md#export-a-dossier) |

Cron jobs (group `default`):

| Job | Schedule | Purpose |
|---|---|---|
| `digitalsignature_reconcile` | every 5 min | Re-queues documents stuck in `sent` (> 15 min) or in processing (> 10 min), and queues certificate fetches for the audit trail |
| `digitalsignature_webhook_retry` | every 5 min | Re-queues webhook deliveries due for retry |
| `digitalsignature_reminder` | 02:00 daily | Customer reminders and admin escalation |
| `digitalsignature_retention_cleanup` | 03:00 daily | GDPR retention: deletes PDFs and personal data of closed documents, and audit trail personal data past its own retention |
| `digitalsignature_quota_check` | 04:00 daily | Provider quota alerts |
| `digitalsignature_dossier_export_cleanup` | hourly (:15) | Deletes expired dossier ZIPs, fails exports that stopped making progress |

## 3. Try the full flow with the dummy provider

The `dummy` provider simulates a signature without any external service: it "signs" the
document automatically after a configurable delay. It has no legal value.

```bash
bin/magento config:set digital_signature/general/enabled 1
bin/magento config:set digital_signature/general/provider dummy
bin/magento config:set digital_signature/providers/dummy/allow 1
bin/magento config:set digital_signature/providers/dummy/auto_sign_delay 60
bin/magento config:set digital_signature/general/default_trigger order_placed
bin/magento config:set digital_signature/audit_trail/data_collection 0
bin/magento cache:flush
```

The `audit_trail/data_collection` line is required: no document is generated until the
merchant chooses whether to collect audit trail data. `0` opts out, which is enough for a test;
see [audit-trail.md](audit-trail.md) before going live.

Then, in the admin:

1. **Digital Signature > Document templates > New Template**: upload a PDF, place the
   signature box with the visual PDF builder (it writes a `{WSIGN#width,height#email}` tag into
   the PDF), choose the scope (*Cart/Order* or *Product*) and whether the signature is
   *required*.
2. Place an order on the storefront. For an optional template, tick the signature checkbox in
   the cart, minicart or checkout; a required template always generates a document.
3. **Digital Signature > Signature documents**: the document moves `pending → generated → sent`.
   The dummy provider sends no callback, so the move to `signed` happens when the reconcile
   cron polls it: a `sent` document is re-checked once it has been idle for 15 minutes, and
   reported as signed if `auto_sign_delay` seconds have passed since it was sent. The signed
   PDF is then available in the admin order view and in the customer's *My Orders* page.

## 4. Switch to a real provider

1. Configure the provider under **Stores > Configuration > Mage-OS > Digital Signature >
   Signature providers** (credentials, expiration, status mapping). See
   [triggers-and-configuration.md](triggers-and-configuration.md) for every config path.
2. Select it as the active provider:

   ```bash
   bin/magento config:set digital_signature/general/provider wssign   # or docusign, adobesign
   ```

3. Confirm the GDPR acknowledgement. Until this flag is set, **every document is forced onto
   the `dummy` provider**, whatever provider is selected:

   ```bash
   bin/magento config:set digital_signature/general/production_ack 1
   ```

4. For DocuSign, set the environment explicitly (it defaults to `demo`, i.e. the sandbox):

   ```bash
   bin/magento config:set digital_signature/providers/docusign/environment production
   ```

## Next steps

- [triggers-and-configuration.md](triggers-and-configuration.md): when documents are generated,
  per-product overrides, quotas, reminders, retention
- [merge-fields.md](merge-fields.md): dynamic order data inside the PDF
- [audit-trail.md](audit-trail.md): document hashes, provider certificates, dossier export
- [rest-api.md](rest-api.md) and [webhooks.md](webhooks.md): ERP/CRM integration
- [sign-provider-integration.md](sign-provider-integration.md): build your own provider connector
- [troubleshooting.md](troubleshooting.md)
