# Triggers and configuration reference

All settings live under **Stores > Configuration > Mage-OS > Digital Signature** (section id
`digital_signature`) and can be set with `bin/magento config:set <path> <value>`. Unless noted,
fields can be set per website and store view.

## Templates

A template is a PDF plus the rules that decide when a document is generated from it
(**Digital Signature > Document templates**).

| Template field | Meaning |
|---|---|
| **Scope** | *Cart/Order*: one document per order. *Product*: one document per order line of each assigned product (children of configurable/bundle products use the parent line). |
| **Signature Required** | Yes: a document is always generated. No: only if the customer ticked the signature checkbox in the cart, minicart or checkout. The check is enforced server-side when the trigger runs. |
| **Generation Trigger** | When the document is generated (see below). Empty = use the global default. |
| **Contract PDF** | Template file, with a different file per store view if needed. Must contain the signature tag `{WSIGN#width,height#email}`, placed with the visual PDF builder, and may contain [merge fields](merge-fields.md). |
| **Assigned Products** | Product-scope templates only. Each assignment can override the trigger. |

## Triggers

| Code | Fires on | Notes |
|---|---|---|
| `order_placed` | `sales_model_service_quote_submit_success` | After the order is saved |
| `invoice_created` | `sales_order_invoice_save_after` | Every invoice save of the order |
| `invoice_paid` | `sales_order_invoice_pay` | Invoice captured/paid |
| `manual` | Admin order grid mass action *Generate signature documents*, or `POST /V1/digitalsignature/orders/:orderId/documents/generate` | Only templates whose resolved trigger is `manual` |

The trigger of a document is resolved in this order, first non-empty value wins:

1. the trigger set on the product assignment (product-scope templates),
2. the trigger set on the template,
3. `digital_signature/general/default_trigger` (default `order_placed`).

A template only produces a document when its resolved trigger equals the trigger that fired.
The same template never creates two active documents for the same order line and trigger.

Example: one order with a rental product that must be signed at checkout and a B2B contract
that waits for payment.

| Template | Scope | Required | Trigger |
|---|---|---|---|
| Rental damage waiver | Product (rental SKUs) | Yes | `order_placed` (product override) |
| B2B supply contract | Cart/Order | Yes | `invoice_paid` |

## General

| Path | Default | Description |
|---|---|---|
| `digital_signature/general/enabled` | `0` | Module enabled |
| `digital_signature/general/provider` | *(empty)* | Active provider code: `wssign`, `docusign`, `adobesign`, `dummy`, or a custom one |
| `digital_signature/general/production_ack` | `0` | GDPR acknowledgement. While `0`, every document is forced onto the `dummy` provider |
| `digital_signature/general/default_trigger` | `order_placed` | Trigger used by templates without their own |
| `digital_signature/general/max_retries` | `3` | Attempts for a retryable provider error before the document goes to `error` |
| `digital_signature/general/reconcile_batch_limit` | `50` | Documents re-queued per reconcile cron run |
| `digital_signature/general/pdfjs_source` | `local` | pdf.js for the PDF builder: bundled copy or CDN |
| `digital_signature/general/pdfjs_cdn_url` | cdnjs pdf.js 2.16.105 | CDN base URL when `pdfjs_source` is the CDN |
| `digital_signature/general/builder_tag_color` | `1 g` | Color of the signature tag text in the PDF: `1 g` white (invisible), `0 g` black, `0.8 g` light gray, `1 0 0 rg` red |

## Providers

Paths follow `digital_signature/providers/<code>/<field>`. Secrets (`password`, `secret_key`,
`client_secret`, `refresh_token`) are stored encrypted: set them from the admin form. Every provider also has a **Status mapping** grid that maps raw
provider statuses to internal ones; see
[troubleshooting.md](troubleshooting.md#unmapped-provider-status).

### wSign (`wssign`)

| Field | Default | Description |
|---|---|---|
| `enabled` | `0` | |
| `platform_url` | | Platform base URL, must be `https://` |
| `tenant` | | Tenant name |
| `username` / `password` | | Owner account credentials (password encrypted) |
| `signature_type` | `OTP_SMS` | Signature method requested from wSign |
| `expiration_days` | `7` | Signature request lifetime |
| `default_phone_prefix` | `+39` | Prefix added to signer phone numbers without one |
| `notes` | | Message shown to the signer |
| `notifier_email` | | Optional notifier email |
| `allowed_ips` | | Comma-separated IP allowlist for the callback endpoint |

### DocuSign (`docusign`)

| Field | Default | Description |
|---|---|---|
| `enabled` | `0` | |
| `environment` | `demo` | `demo` (sandbox) or `production`. A warning banner is shown while it is not `production` |
| `integration_key` | | Integration Key (Client ID) |
| `secret_key` | | Client Secret (encrypted) |
| `account_id` | | API Account ID |
| `user_id` | | API User ID (GUID) used for JWT impersonation |
| `private_key` | | RSA private key for JWT |
| `expiration_days` | `7` | Envelope lifetime |
| `notes` | | Email message to the signer |

### Adobe Acrobat Sign (`adobesign`)

| Field | Default | Description |
|---|---|---|
| `enabled` | `0` | |
| `client_id` | | Application ID |
| `client_secret` | | Client Secret (encrypted) |
| `refresh_token` | | OAuth refresh token (encrypted) |
| `api_access_point` | | API access point of your Adobe Sign shard |
| `expiration_days` | `7` | Agreement lifetime |
| `notes` | | Message to the signer |

### Dummy (`dummy`)

| Field | Default | Description |
|---|---|---|
| `allow` | `0` | Must be `1` for the dummy provider to accept documents |
| `auto_sign_delay` | `60` | Seconds after which a sent document is reported as signed |

## Quotas and costs

Each real provider can track how many signature requests were consumed against a quota.
Consumption counts the provider's documents created since the start of the current period,
excluding documents in `error`.

| Field (`digital_signature/providers/<code>/...`) | Default | Description |
|---|---|---|
| `quota_enabled` | `0` | Enable tracking for this provider |
| `quota_type` | wSign `prepaid_pool`, DocuSign `monthly_allowance`, Adobe Sign `annual_allowance` | `prepaid_pool`, `monthly_allowance` or `annual_allowance` |
| `total_quota` | | Signatures included in the pool or period |
| `alert_threshold_percent` | `15` | Warn when the remaining share is at or below this percentage |
| `alert_threshold_count` | `20` | Also warn when this many signatures or fewer are left |
| `exceeded_behavior` | `allow_and_log` | `allow_and_log` keeps sending and logs a warning; `block_dispatch` blocks new signature requests |
| `reset_day` | `1` | `monthly_allowance` only: day of the month (1-28) the allowance renews |
| `quota_start_date` | | `prepaid_pool` only: purchase date of the credit package, `YYYY-MM-DD` |
| `notification_email` | | Recipient of this provider's quota alerts |

Period start by quota type:

| `quota_type` | Period start |
|---|---|
| `prepaid_pool` | `quota_start_date` if set, otherwise all documents ever sent to the provider |
| `monthly_allowance` | Day `reset_day` of the current month (or of the previous month, before that day) |
| `annual_allowance` | January 1st of the current year |

Example from the command line:

```bash
# Block new signature requests once the DocuSign quota is exhausted
bin/magento config:set digital_signature/providers/docusign/exceeded_behavior block_dispatch
# Monthly allowance renewing on the 15th
bin/magento config:set digital_signature/providers/docusign/reset_day 15
```

A provider is in warning when the remaining share is at or below `alert_threshold_percent`, or
the remaining count is at or below `alert_threshold_count`, or the quota is exhausted. The
`digitalsignature_quota_check` cron (04:00 daily) then raises an admin notification and sends
the `quota_warning` email to the provider's `notification_email`, falling back to
`digital_signature/notifications/admin_recipient` and then to the general store contact. The
**Signature documents** page shows a green/yellow/red bar per provider.

With `exceeded_behavior = block_dispatch`, a document for a provider whose quota is exhausted
goes to `error` with the message *Signature request blocked: provider "…" has exhausted its
quota*, instead of being sent.

## Email notifications

| Path | Default | Description |
|---|---|---|
| `digital_signature/notifications/identity` | `general` | Sender identity |
| `digital_signature/notifications/admin_recipient` | | Admin email; falls back to the general store contact |
| `digital_signature/notifications/customer_ready_enabled` | `0` | Email the customer when the document is ready to sign |
| `digital_signature/notifications/customer_signed_enabled` | `1` | Email the customer when the document is signed |
| `digital_signature/notifications/customer_outcome_enabled` | `0` | Email the customer when the document expires or is declined |
| `digital_signature/notifications/admin_error_enabled` | `1` | Email the admin when a document ends in `error` |
| `digital_signature/notifications/admin_outcome_enabled` | `1` | Email the admin when a document expires or is declined |

Each `*_enabled` flag has a matching `*_template` path for a custom email template.

## Reminders

Run daily at 02:00 on documents still in `sent`.

| Path | Default | Description |
|---|---|---|
| `digital_signature/reminders/customer_enabled` | `0` | Send reminders to the signer |
| `digital_signature/reminders/customer_threshold_days` | `3` | Days before the first reminder |
| `digital_signature/reminders/customer_interval_days` | `3` | Days between reminders |
| `digital_signature/reminders/customer_max_reminders` | `2` | Maximum reminders per document |
| `digital_signature/reminders/admin_enabled` | `1` | Escalate to the admin |
| `digital_signature/reminders/admin_threshold_days` | `7` | Days before the escalation email |
| `digital_signature/reminders/batch_limit` | `50` | Documents processed per run |

## Retention (GDPR)

| Path | Default | Description |
|---|---|---|
| `digital_signature/retention/enabled` | `0` | Enable automatic purge |
| `digital_signature/retention/retention_days` | `3650` | Days after the last update of a final document |
| `digital_signature/retention/batch_limit` | `200` | Documents purged per run |

The `digitalsignature_retention_cleanup` cron (03:00 daily) picks documents in a final status
(`signed`, `declined`, `expired`, `canceled`) not updated for `retention_days`, deletes the
original and signed PDF files, clears `signer_email` and `signer_phone`, sets `purged_at` and
writes a log entry. The document row and its status history stay as the audit record.

## Webhooks

See [webhooks.md](webhooks.md#configuration-reference).
