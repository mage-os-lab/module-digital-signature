# Merge fields: order data inside the PDF

A template PDF can contain `{FIELD:<code>}` placeholders. When the document is generated, each
placeholder is replaced with a value taken from the order (or invoice). New fields are added by
registering a class in `di.xml`, without touching the core module.

## Built-in fields

| Placeholder | Value | Available for triggers |
|---|---|---|
| `{FIELD:order_number}` | Order increment id, e.g. `000001001` | all |
| `{FIELD:customer_name}` | Customer first name + last name | all |
| `{FIELD:grand_total}` | Order grand total formatted in the order currency | all |
| `{FIELD:order_date}` | Order creation date, medium format, store locale | all |
| `{FIELD:invoice_date}` | Date of the most recent invoice of the order | all except `order_placed` |

`invoice_date` is filled for the `invoice_created` and `invoice_paid` triggers. For `manual`
it is accepted but resolves to an empty string, because no invoice is loaded for that trigger.

## Writing placeholders in the template

- Type the placeholder as plain text in your editor (Word, LibreOffice, Google Docs) and export
  to PDF. The text must end up as one contiguous string in the PDF content stream: avoid
  applying different styles, kerning or spell-check corrections inside the braces.
- Field codes are lowercase letters, digits and underscores: `[a-z0-9_]+`.
- Values are written with the font already used by the placeholder, so the font must contain
  the glyphs of the values (use an embedded font with the characters of your customers' names).
- PDFs with object streams or encryption are rejected at upload; see the *Known limitations*
  section of the README.

When a template is saved, every `{FIELD:...}` found in its PDF is checked: an unknown code, or
a code not available for the template's trigger, blocks the save with an explicit error such
as `The merge field tag "{FIELD:invoice_date}" is not compatible with the selected trigger
"order_placed".`

The signature box (`{WSIGN#width,height#email}`) is a different tag, placed with the visual
PDF builder; see [sign-provider-integration.md](sign-provider-integration.md).

## Add a custom field

A merge field implements `MageOS\DigitalSignature\Api\MergeFieldProviderInterface`:

```php
interface MergeFieldProviderInterface
{
    public function getCode(): string;

    public function resolve(\MageOS\DigitalSignature\Model\MergeField\Context $context): string;

    public function isAvailableForTrigger(string $triggerCode): bool;
}
```

`Context` gives access to the data of the document being generated:

| Method | Returns |
|---|---|
| `getOrder()` | `Magento\Sales\Api\Data\OrderInterface` |
| `getInvoice()` | `Magento\Sales\Api\Data\InvoiceInterface\|null`, the latest invoice, set only for `invoice_created` / `invoice_paid` |
| `getStoreId()` | Store id of the document |

### Example: VAT number from the billing address

`Vendor/SignatureFields/Model/MergeField/BillingVatId.php`:

```php
<?php
declare(strict_types=1);

namespace Vendor\SignatureFields\Model\MergeField;

use MageOS\DigitalSignature\Api\MergeFieldProviderInterface;
use MageOS\DigitalSignature\Model\MergeField\Context;

class BillingVatId implements MergeFieldProviderInterface
{
    public function getCode(): string
    {
        return 'billing_vat_id';
    }

    public function resolve(Context $context): string
    {
        $address = $context->getOrder()->getBillingAddress();

        return $address ? (string)$address->getVatId() : '';
    }

    public function isAvailableForTrigger(string $triggerCode): bool
    {
        return true;
    }
}
```

`Vendor/SignatureFields/etc/di.xml`, adding an item to the merge field pool (Magento merges
this array with the core one):

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="MageOS\DigitalSignature\Model\MergeField\Pool">
        <arguments>
            <argument name="providers" xsi:type="array">
                <item name="billing_vat_id" xsi:type="object">Vendor\SignatureFields\Model\MergeField\BillingVatId</item>
            </argument>
        </arguments>
    </type>
</config>
```

`Vendor/SignatureFields/etc/module.xml` must load after the core module:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Vendor_SignatureFields">
        <sequence>
            <module name="MageOS_DigitalSignature"/>
        </sequence>
    </module>
</config>
```

Then use `{FIELD:billing_vat_id}` in the template PDF.

Rules for custom fields:

- The placeholder is looked up by the `di.xml` item name, so that name is the field code. Keep
  `getCode()` identical to it, and use only `[a-z0-9_]+`.
- `resolve()` returns a plain string. Format numbers, dates and currencies yourself, in the
  store's locale. Parentheses and backslashes are escaped by the module.
- Return `false` from `isAvailableForTrigger()` for triggers where the data cannot exist yet
  (e.g. invoice data on `order_placed`), so the admin gets an error when saving the template
  instead of an empty value in a signed contract.
- Keep `resolve()` fast and side-effect free: it runs inside the queue consumer for every
  generated document.

## Reading the pool in code

```php
use MageOS\DigitalSignature\Api\MergeFieldPoolInterface;

public function __construct(private readonly MergeFieldPoolInterface $mergeFieldPool)
{
}

public function availableCodes(): array
{
    return array_keys($this->mergeFieldPool->getAll());
}
```

`get(string $code)` throws `LocalizedException` for an unknown code; check with `has()` first.
