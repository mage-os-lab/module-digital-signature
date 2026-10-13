# Outbound webhooks

The module can notify external systems (ERP, CRM, automation tools) every time a signature
document changes status. Each notification is a JSON `POST` signed with HMAC-SHA256, delivered
asynchronously through the message queue, with retries and backoff.

## Enable and subscribe

1. Enable webhooks (store scope):

   ```bash
   bin/magento config:set digital_signature/webhooks/enabled 1
   ```

2. **Digital Signature > Webhook > New Webhook Subscription** and fill in:
   - **Target URL**: an `http` or `https` URL (use `https` in production).
   - **HMAC Secret**: a long random string shared with the receiver. It is stored encrypted and
     is required when the subscription is created. Leave it empty on edit to keep the current
     one.
   - **Store View**: one store view, or none for all stores.
   - **Enabled**.

3. Make sure the `digitalsignatureWebhookDispatch` consumer and the cron are running
   ([getting-started.md](getting-started.md#2-run-the-consumers)).

Several subscriptions can be active at the same time: every matching subscription receives its
own delivery.

## When a webhook is sent

A delivery is created for each document status transition recorded as a status change:

| Transition | Cause |
|---|---|
| `null → pending` | A trigger created the document, or a regeneration created its replacement |
| `pending → generated` | The PDF was built |
| `generated → sent` | The provider accepted the signature request |
| `sent → signed` / `declined` / `expired` | Final outcome from the provider |
| `* → canceled` | The document was canceled for regeneration (the replacement is a new document) |

A document that ends in `error` (permanent provider failure or retries exhausted) does **not**
send a webhook: that move is logged as an error event, and the admin is notified by email
instead. Poll the [REST API](rest-api.md) with `status=error` if your integration needs it.

## Request format

```http
POST /your/endpoint HTTP/1.1
Content-Type: application/json
X-Signature: sha256=3f1c0a9e5b...

{"delivery_id":981,"document_id":42,"order_id":1001,"status_from":"sent","status_to":"signed","provider_code":"wssign","timestamp":"2026-10-11T09:14:52+00:00"}
```

| Field | Type | Description |
|---|---|---|
| `delivery_id` | int | Unique per delivery. Retries of the same delivery reuse it: use it to deduplicate |
| `document_id` | int | Document id, usable with `GET /V1/digitalsignature/documents/:documentId` |
| `order_id` | int | Magento order entity id (not the increment id) |
| `status_from` | string\|null | Previous status, `null` for a new document |
| `status_to` | string | New status |
| `provider_code` | string | `wssign`, `docusign`, `adobesign`, `dummy` or a custom provider code |
| `timestamp` | string | ISO 8601 time the payload was built |

The payload carries no personal data. Fetch the document through the REST API if you need
more details.

## Verify the signature

`X-Signature` is `sha256=` followed by the hex HMAC-SHA256 of the **raw request body**, keyed
with the subscription secret. Always compute it on the raw bytes before parsing the JSON, and
compare in constant time.

PHP:

```php
<?php
$secret = getenv('DIGITALSIGNATURE_WEBHOOK_SECRET');
$body = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
$received = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
// Deduplicate on $event['delivery_id'], then handle $event['status_to'].
http_response_code(204);
```

Node.js (Express):

```js
const crypto = require('crypto');
const express = require('express');

const app = express();
const secret = process.env.DIGITALSIGNATURE_WEBHOOK_SECRET;

app.post('/webhooks/digital-signature', express.raw({ type: 'application/json' }), (req, res) => {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(req.body).digest('hex');
  const received = req.get('X-Signature') || '';
  const valid = received.length === expected.length
    && crypto.timingSafeEqual(Buffer.from(received), Buffer.from(expected));
  if (!valid) {
    return res.sendStatus(401);
  }
  const event = JSON.parse(req.body.toString('utf8'));
  // Deduplicate on event.delivery_id, then handle event.status_to.
  res.sendStatus(204);
});
```

Python (Flask):

```python
import hashlib, hmac, os
from flask import Flask, request, abort

app = Flask(__name__)
SECRET = os.environ["DIGITALSIGNATURE_WEBHOOK_SECRET"].encode()

@app.post("/webhooks/digital-signature")
def digital_signature():
    body = request.get_data()
    expected = "sha256=" + hmac.new(SECRET, body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, request.headers.get("X-Signature", "")):
        abort(401)
    event = request.get_json()
    # Deduplicate on event["delivery_id"], then handle event["status_to"].
    return "", 204
```

Each attempt is signed with the subscription's **current** secret, so after a secret rotation
pending retries are signed with the new one.

## Delivery, retries and failures

- Any `2xx` response marks the delivery as delivered. Respond quickly and do the work
  asynchronously: the request times out after 15 seconds (5 seconds to connect).
- Redirects are **not** followed and TLS certificates are verified. Point the subscription at
  the final URL.
- `408`, `429`, `5xx` and network errors are retried. Any other `4xx` (e.g. `404`, `410`) is a
  permanent failure and is not retried.
- Retry delays: 1 minute, 5 minutes, 30 minutes, then every 2 hours, up to
  `digital_signature/webhooks/max_attempts` attempts in total (default `5`). The
  `digitalsignature_webhook_retry` cron re-queues due deliveries every 5 minutes, at most
  `digital_signature/webhooks/batch_limit` per run (default `50`).
- When a delivery fails for good, the admin receives the *Webhook failure* email (configurable
  with `digital_signature/webhooks/failure_notification_enabled` and
  `failure_notification_template`).
- Deliveries still pending for a subscription that has since been disabled or deleted are
  marked as failed when they are processed, without being sent.

Deliveries can arrive more than once (a retry after a timeout on your side) and out of order
(a retry of an older transition after a newer one). Deduplicate on `delivery_id`, and when
order matters, compare with the current status from the REST API.

## Configuration reference

| Path | Default | Description |
|---|---|---|
| `digital_signature/webhooks/enabled` | `0` | Master switch (store scope) |
| `digital_signature/webhooks/max_attempts` | `5` | Total delivery attempts before giving up |
| `digital_signature/webhooks/batch_limit` | `50` | Deliveries re-queued per cron run |
| `digital_signature/webhooks/failure_notification_enabled` | `1` | Email the admin on permanent failure |
| `digital_signature/webhooks/failure_notification_template` | `digital_signature_webhooks_failure_notification_template` | Email template |
