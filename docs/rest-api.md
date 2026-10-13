# REST API

The module exposes signature documents over the standard Magento Web API, so an ERP, CRM or
back-office tool can read document status and trigger generation without the admin panel.
For push notifications on status changes, see [webhooks.md](webhooks.md).

| Method | Endpoint | Service method | ACL resource |
|---|---|---|---|
| `GET` | `/V1/digitalsignature/documents/:documentId` | `DocumentRepositoryInterface::getById` | `MageOS_DigitalSignature::document` |
| `GET` | `/V1/digitalsignature/documents` | `DocumentRepositoryInterface::getList` | `MageOS_DigitalSignature::document` |
| `POST` | `/V1/digitalsignature/orders/:orderId/documents/generate` | `DocumentGenerationManagementInterface::generate` | `MageOS_DigitalSignature::document_action` |
| `POST` | `/V1/digitalsignature/documents/:documentId/regenerate` | `DocumentGenerationManagementInterface::regenerate` | `MageOS_DigitalSignature::document_action` |

All endpoints are under `/rest/<store_code>/` (or `/rest/V1/...` for the default store view).

## Authentication

Use either an admin token or a Magento **integration** (recommended for machine-to-machine).

1. **System > Extensions > Integrations > Add New Integration**, grant the resources
   *Digital Signature > Signature documents* and, to generate/regenerate, *Document actions
   (API)*. Activate it and copy the access token.
2. Map the integration to its stores under **Digital Signature > API Integrations** (see
   [Store scoping](#store-scoping)). An integration that is not mapped sees no documents at all.
3. On Magento 2.4.4+, integration access tokens are accepted as bearer tokens only when
   *Stores > Configuration > Services > OAuth > Consumer Settings > Allow OAuth Access Tokens
   to be used as standalone Bearer tokens* is enabled:

   ```bash
   bin/magento config:set oauth/consumer/enable_integration_as_bearer 1
   ```

```bash
export BASE_URL="https://shop.example.com"
export TOKEN="<integration access token>"
```

## Get a document

```bash
curl -s "$BASE_URL/rest/V1/digitalsignature/documents/42" \
  -H "Authorization: Bearer $TOKEN"
```

Example response:

```json
{
  "document_id": 42,
  "order_id": 1001,
  "order_item_id": 0,
  "template_id": 3,
  "store_id": 1,
  "provider_code": "wssign",
  "provider_process_id": "a1b2c3d4",
  "status": "signed",
  "is_active": true,
  "trigger_code": "order_placed",
  "signer_email": "customer@example.com",
  "signer_phone": "+390000000000",
  "retry_count": 0,
  "reminder_count": 1,
  "last_reminder_at": "2026-10-10 02:00:04",
  "updated_at": "2026-10-11 09:14:52"
}
```

Field notes:

- `order_item_id` is `0` for a cart/order-level document; for a product-scope template it is
  the id of the order line the document belongs to.
- `is_active` is `false` for documents replaced by a regeneration (they keep their history).
- `status` is one of `pending`, `generated`, `sent`, `signed`, `declined`, `expired`, `error`,
  `canceled`. `signed`, `declined`, `expired` and `canceled` are final.
- Fields with no value (e.g. `error_message`, `purged_at`) are omitted from the response.
- `signer_email` and `signer_phone` are no longer returned and `purged_at` is set once the
  retention cron has removed the PDF files and personal data (see [triggers-and-configuration.md](triggers-and-configuration.md#retention-gdpr)).
- The API returns metadata only. Internal fields (callback token hash, storage paths of the PDFs)
  are not part of the output. Signed PDFs are downloaded from the admin order view or by the
  customer from *My Orders*.

## Search documents

`getList` takes standard Magento `searchCriteria` parameters. Filterable fields are the
document columns, e.g. `order_id`, `status`, `provider_code`, `store_id`, `is_active`,
`updated_at`.

All signed documents of one order:

```bash
curl -s -G "$BASE_URL/rest/V1/digitalsignature/documents" \
  -H "Authorization: Bearer $TOKEN" \
  --data-urlencode "searchCriteria[filterGroups][0][filters][0][field]=order_id" \
  --data-urlencode "searchCriteria[filterGroups][0][filters][0][value]=1001" \
  --data-urlencode "searchCriteria[filterGroups][1][filters][0][field]=status" \
  --data-urlencode "searchCriteria[filterGroups][1][filters][0][value]=signed"
```

Documents still waiting for a signature, oldest first, 50 per page:

```bash
curl -s -G "$BASE_URL/rest/V1/digitalsignature/documents" \
  -H "Authorization: Bearer $TOKEN" \
  --data-urlencode "searchCriteria[filterGroups][0][filters][0][field]=status" \
  --data-urlencode "searchCriteria[filterGroups][0][filters][0][value]=sent" \
  --data-urlencode "searchCriteria[filterGroups][1][filters][0][field]=is_active" \
  --data-urlencode "searchCriteria[filterGroups][1][filters][0][value]=1" \
  --data-urlencode "searchCriteria[sortOrders][0][field]=updated_at" \
  --data-urlencode "searchCriteria[sortOrders][0][direction]=ASC" \
  --data-urlencode "searchCriteria[pageSize]=50" \
  --data-urlencode "searchCriteria[currentPage]=1"
```

The response is `{"items": [...], "search_criteria": {...}, "total_count": N}`.

## Generate documents for an order

```bash
curl -s -X POST "$BASE_URL/rest/V1/digitalsignature/orders/1001/documents/generate" \
  -H "Authorization: Bearer $TOKEN"
```

This runs the **`manual` trigger** for the order, exactly like the *Generate signature
documents* mass action in the admin order grid. Only templates whose resolved trigger is
`manual` produce a document (see
[triggers-and-configuration.md](triggers-and-configuration.md#triggers)); templates bound to
`order_placed`, `invoice_created` or `invoice_paid` are ignored. Optional templates still need
the customer's opt-in on the order; required templates always generate.

Generation is queued: the call returns immediately with no payload and documents appear as `pending`,
then move on as the `digitalsignatureDocumentProcess` consumer picks them up. An order outside
the caller's scope returns `404`.

## Regenerate a document

```bash
curl -s -X POST "$BASE_URL/rest/V1/digitalsignature/documents/42/regenerate" \
  -H "Authorization: Bearer $TOKEN"
```

Cancels the active document (status `canceled`, `is_active=false`, best-effort cancel at the
provider) and queues a new one for the same order, template and trigger. Returns the id of the
new document, e.g. `57`. Regenerating a document that is no longer active returns `400`: use
the active one.

## Store scoping

Integrations are scoped by store, fail-closed:

| Caller | Documents visible |
|---|---|
| Admin token | All stores |
| Integration mapped and enabled under *Digital Signature > API Integrations* | Only the mapped stores |
| Integration not mapped, or mapping disabled | None |

A document or order outside the caller's scope answers `404 Not Found`, not `403`, so the API
does not reveal that it exists. On `getList`, the scope filter is applied to the query itself,
so `total_count` and pagination only count visible documents.

## Errors

| HTTP | When |
|---|---|
| `401` | Missing or invalid token |
| `403` | Token without the required ACL resource |
| `404` | Document/order not found or outside the caller's scope |
| `400` | Regenerating a document that is not active |

## PHP usage

Inside Magento code, use the same service contracts instead of HTTP:

```php
use Magento\Framework\Api\SearchCriteriaBuilder;
use MageOS\DigitalSignature\Api\DocumentGenerationManagementInterface;
use MageOS\DigitalSignature\Api\DocumentRepositoryInterface;

class SignedContracts
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentGenerationManagementInterface $generation,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /** @return \MageOS\DigitalSignature\Api\Data\DocumentInterface[] */
    public function forOrder(int $orderId): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('order_id', $orderId)
            ->addFilter('status', 'signed')
            ->create();

        return $this->documentRepository->getList($criteria)->getItems();
    }

    public function requestSignature(int $orderId): void
    {
        $this->generation->generate($orderId);
    }
}
```
