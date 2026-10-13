# Troubleshooting

Every document keeps a status history in the `mageos_digitalsignature_document_log` table
(status changes, provider callbacks, errors). Check it first, then the cases below. Module log
lines are prefixed with `DigitalSignature:` in `var/log/system.log`.

## No document is created after an order

Checks, in order:

1. `digital_signature/general/enabled` is `1` for the order's store.
2. `digital_signature/general/provider` is not empty. With no active provider, triggers do
   nothing.
3. A template is active and its **resolved trigger** matches the event: the product
   assignment override, then the template trigger, then
   `digital_signature/general/default_trigger`. A template on `invoice_paid` creates nothing
   when the order is placed. See
   [triggers-and-configuration.md](triggers-and-configuration.md#triggers).
4. For an optional template (*Signature Required* = No), the customer ticked the signature
   checkbox. Required templates always generate.
5. For product-scope templates, the ordered product (or the parent of a configurable/bundle
   line) is assigned to the template.
6. `POST .../orders/:orderId/documents/generate` and the admin mass action only run the
   `manual` trigger.

## Document stuck in `pending` or `generated`

Generation runs in the `digitalsignatureDocumentProcess` consumer. Make sure consumers run
(cron with `cron_consumers_runner`, or `bin/magento queue:consumers:start
digitalsignatureDocumentProcess`). The reconcile cron re-queues documents idle in these
statuses for more than 10 minutes, up to `digital_signature/general/max_retries` times.

## Document stuck in `sent`

The provider calls back `digitalsignature/callback/index/id/<id>/token/<token>` when the
status changes; the module then fetches the real status. If the callback never arrives
(firewall, `allowed_ips` mismatch, provider without callbacks, the `dummy` provider), the
reconcile cron polls documents idle in `sent` for 15 minutes. Check that:

- the store base URL is reachable from the provider over HTTPS;
- `digital_signature/providers/<code>/allowed_ips`, if set, contains the provider's IPs (the
  module checks `REMOTE_ADDR`; behind a proxy, configure Magento to see the real client IP);
- the `digitalsignatureDocumentRefresh` consumer and the cron are running.

## Unmapped provider status

Log line: `DigitalSignature: unmapped provider status "<STATUS>" (document <id>, provider
<code>)`, and a history entry *Unmapped provider status: <STATUS>*. The document keeps its
status until the provider reports something the module understands.

Fix it without a deploy: open the provider's **Status mapping** grid under **Stores >
Configuration > Mage-OS > Digital Signature > Signature providers**, add a row with the raw
status (`<STATUS>` as logged) and pick the internal status. Only final statuses (`signed`,
`declined`, `expired`, `canceled`) change the document; map intermediate provider statuses to
`sent` or leave them unmapped.

## Documents are signed, but not in the provider account you check (DocuSign)

`digital_signature/providers/docusign/environment` defaults to `demo`: envelopes go to the
DocuSign developer sandbox. Nothing fails and no error is logged. The DocuSign configuration
shows a warning banner while the environment is not `production`. Set it explicitly:

```bash
bin/magento config:set digital_signature/providers/docusign/environment production
```

## Every document uses the dummy provider

While `digital_signature/general/production_ack` is `0`, all documents are forced onto
`dummy`, whatever provider is selected. The log says `unconfirmed production mode (store N):
provider forced to "dummy"`. Confirm the GDPR acknowledgement in the General group once the
real provider is configured.

If `dummy` is not allowed either, documents go to `error` with *The dummy provider is not
enabled: enable it explicitly in the configuration (test environments only).*

## Document in `error` with "has exhausted its quota"

The provider's quota is exhausted and `exceeded_behavior` is `block_dispatch`. Raise
`total_quota`, move to the next period, switch provider, or set
`exceeded_behavior` to `allow_and_log`. See
[triggers-and-configuration.md](triggers-and-configuration.md#quotas-and-costs).

## Template PDF rejected

| Message | Cause and fix |
|---|---|
| *The PDF exceeds the maximum size of 10 MB.* | Reduce images or split the document |
| *The uploaded file is not a valid PDF.* | The file is not a PDF or is corrupted |
| *Unsupported PDF: ...* | Usually encryption or object streams (Acrobat "object level compression", some LaTeX setups). Re-export from LibreOffice, Word or Google Docs, or disable object-level compression |
| *No signature tag found in the template PDF* | Place the signature box with the PDF builder before saving |
| *The merge field tag "{FIELD:x}" is not supported or registered.* | Typo, or the custom field module is not enabled; see [merge-fields.md](merge-fields.md) |
| *The merge field tag "{FIELD:x}" is not compatible with the selected trigger "y".* | E.g. `invoice_date` on an `order_placed` template: change the trigger or remove the field |

## The signature checkbox is missing from the cart

The checkbox block is added to the `cart.summary` layout container (`checkout_cart_index.xml`).
A custom theme that removes or renames that container hides the checkbox, without errors.
Required templates are still enforced server-side, but the customer cannot opt in to optional
ones. Make sure your theme keeps `cart.summary`, or move the block into the container your
theme uses. On Hyvä, install the `mage-os/module-digital-signature-hyva` module.

## Webhooks are not received

- `digital_signature/webhooks/enabled` is `1` for the document's store, and the subscription is
  enabled for that store view (or for all stores).
- The `digitalsignatureWebhookDispatch` consumer runs.
- The endpoint returns `2xx` within 15 seconds, does not redirect, and has a valid TLS
  certificate.
- Any `4xx` other than `408`/`429` is final, without retries. The admin gets a *Webhook
  failure* email when a delivery gives up.
- Transitions to `error` do not send webhooks.

Details in [webhooks.md](webhooks.md).

## REST API returns 404 for an existing document

The integration is not mapped to the document's store under **Digital Signature > API
Integrations**, or the mapping is disabled. Out-of-scope documents answer `404` on purpose.
See [rest-api.md](rest-api.md#store-scoping).
