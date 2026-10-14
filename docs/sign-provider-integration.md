# Building a signature provider connector

This guide explains how to connect `MageOS_DigitalSignature` to another electronic/digital
signature service (Namirial, InfoCert, Yousign, a regional provider...) without modifying the
core module.

Providers follow the same pluggable pattern as Magento payment methods: one interface, a pool
registered through `di.xml`, and an independent satellite module per provider. The core ships
four connectors: `wssign`, `docusign`, `adobesign` and `dummy` (see
[available-connectors.md](available-connectors.md)).

## 1. The contract: `SignProviderInterface`

`MageOS\DigitalSignature\Api\SignProviderInterface`:

```php
interface SignProviderInterface
{
    public function getCode(): string;
    public function getLabel(): string;
    public function isEnabled(?int $storeId = null): bool;

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult;
    public function fetchStatus(DocumentInterface $document): StatusResult;
    public function downloadSignedPdf(DocumentInterface $document): string;
    public function cancel(DocumentInterface $document): void;
    public function mapStatus(string $providerStatus): ?string;
}
```

**Key rule: connectors never modify the document and never access the storage.** They receive
content as binary strings and return result DTOs. Saving the status, the signed PDF and the
history is the job of the calling service (`DocumentProcessor`). A connector only reads
document data (signer email and phone, process id, store) and returns the requested DTOs.

### What each method must do

| Method | Called | Must |
|---|---|---|
| `getCode()` | Always | Return the unique code (e.g. `acmesign`), used as pool key and config prefix `digital_signature/providers/acmesign/*` |
| `getLabel()` | Admin provider select | Return a translated, human-readable label |
| `isEnabled()` | Before every operation | Check that the provider is enabled and its credentials are present |
| `start()` | Document moving from `generated` to `sent` | Upload `$pdfContent` (binary), start the signature process, **pass the callback URL** (§6) to the service, return `StartResult` with the remote process id |
| `fetchStatus()` | After a callback and on reconcile polling | Query the service, return `StatusResult` with the raw provider status |
| `downloadSignedPdf()` | Only when `mapStatus()` resolves to `signed` | Return the signed PDF (binary) |
| `cancel()` | Regeneration of an active document | Cancel the remote process. **Must be idempotent**: no error if the process no longer exists |
| `mapStatus()` | After every `fetchStatus()` | Translate the raw status to a `Model\Document\Status` constant, or `null` when unknown (logged, no transition) |

### Result DTOs

- `MageOS\DigitalSignature\Model\Provider\Result\StartResult`: immutable, exposes
  `getProcessId()` (saved as `provider_process_id`) and `getRawResponse()` (kept in the
  history for audit).
- `MageOS\DigitalSignature\Model\Provider\Result\StatusResult`: exposes `getRawStatus()`
  (passed to `mapStatus()`) and `getRawResponse()`.

## 2. Register the connector in the pool (`di.xml`)

```php
interface SignProviderPoolInterface
{
    /** @return SignProviderInterface[] indexed by code */
    public function getProviders(): array;

    /** @throws NoSuchEntityException when the code is not registered */
    public function get(string $code): SignProviderInterface;
}
```

`Model\Provider\Pool` checks at runtime that every injected item implements
`SignProviderInterface` and throws `\InvalidArgumentException` otherwise, so a wiring mistake in
your module shows up immediately.

The core registers its connectors in `etc/di.xml`:

```xml
<type name="MageOS\DigitalSignature\Model\Provider\Pool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="wssign" xsi:type="object">MageOS\DigitalSignature\Model\Provider\WsSign</item>
            <item name="docusign" xsi:type="object">MageOS\DigitalSignature\Model\Provider\Docusign</item>
            <item name="adobesign" xsi:type="object">MageOS\DigitalSignature\Model\Provider\AdobeSign</item>
            <item name="dummy" xsi:type="object">MageOS\DigitalSignature\Model\Provider\Dummy</item>
        </argument>
    </arguments>
</type>
```

Do not edit that file. In your satellite module, declare the same `<type>` with one more
`<item>`; Magento merges `di.xml` arrays across modules:

```xml
<!-- Vendor/AcmeSign/etc/di.xml -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="MageOS\DigitalSignature\Model\Provider\Pool">
        <arguments>
            <argument name="providers" xsi:type="array">
                <item name="acmesign" xsi:type="object">Vendor\AcmeSign\Model\Provider\AcmeSign</item>
            </argument>
        </arguments>
    </type>
</config>
```

The satellite module declares `<sequence><module name="MageOS_DigitalSignature"/></sequence>`
in `etc/module.xml`, so the interfaces and base classes are loaded first. The item name must
equal `getCode()`.

## 3. Minimal example: the `Dummy` provider

`Model/Provider/Dummy.php` makes no network calls and is the best skeleton to start from:

- `const CODE = 'dummy'`, matching the pool key.
- Its own raw statuses (`DUMMY_IN_PROGRESS`, `DUMMY_SIGNED`). There is no shared vocabulary:
  each provider has its own raw statuses, translated only in `mapStatus()`.
- `start()` validates the content, builds a process id and returns
  `new StartResult($processId)`.
- `fetchStatus()` decides the current raw status and returns `new StatusResult($rawStatus)`.
- `downloadSignedPdf()` returns the binary PDF.
- `cancel()` is a no-op (no remote state); a real provider calls the service's cancel API.
- A private `assertAllowed()` guard, called by every public method except the getters,
  `isEnabled()` and `mapStatus()`, throws `ProviderException::permanent()` when the provider is
  not explicitly enabled.

A skeleton for a new provider:

```php
<?php
declare(strict_types=1);

namespace Vendor\AcmeSign\Model\Provider;

use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Api\SignProviderInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Document\Status;
use MageOS\DigitalSignature\Model\Provider\ProviderConfig;
use MageOS\DigitalSignature\Model\Provider\Result\StartResult;
use MageOS\DigitalSignature\Model\Provider\Result\StatusResult;
use Magento\Store\Model\StoreManagerInterface;
use Vendor\AcmeSign\Model\Provider\AcmeSign\Client;

class AcmeSign implements SignProviderInterface
{
    public const CODE = 'acmesign';

    public function __construct(
        private readonly ProviderConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly Client $client
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('Acme Sign');
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isSetFlag(self::CODE, 'enabled', $storeId)
            && (string)$this->config->getSecret(self::CODE, 'api_key', $storeId) !== '';
    }

    public function start(DocumentInterface $document, string $pdfContent, string $callbackToken): StartResult
    {
        $storeId = (int)$document->getStoreId();
        if (!$this->isEnabled($storeId)) {
            throw ProviderException::permanent(__('Acme Sign is not configured.'));
        }
        $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl();
        $callbackUrl = $baseUrl . 'digitalsignature/callback/index/id/'
            . (int)$document->getDocumentId() . '/token/' . $callbackToken;

        $response = $this->client->createEnvelope(
            $pdfContent,
            (string)$document->getSignerEmail(),
            $callbackUrl,
            $storeId
        );

        return new StartResult($response['id'], json_encode($response) ?: null);
    }

    public function fetchStatus(DocumentInterface $document): StatusResult
    {
        $response = $this->client->getEnvelope(
            (string)$document->getProviderProcessId(),
            (int)$document->getStoreId()
        );

        return new StatusResult($response['status'], json_encode($response) ?: null);
    }

    public function downloadSignedPdf(DocumentInterface $document): string
    {
        return $this->client->downloadSigned(
            (string)$document->getProviderProcessId(),
            (int)$document->getStoreId()
        );
    }

    public function cancel(DocumentInterface $document): void
    {
        // Must not fail when the envelope is already gone (404 tolerated by the client).
        $this->client->cancelEnvelope(
            (string)$document->getProviderProcessId(),
            (int)$document->getStoreId()
        );
    }

    public function mapStatus(string $providerStatus): ?string
    {
        // Admin-configured mapping first, so new statuses need no deploy.
        $configured = $this->config->mapConfiguredStatus(self::CODE, $providerStatus);
        if ($configured !== null) {
            return $configured;
        }

        return match ($providerStatus) {
            'completed' => Status::SIGNED,
            'rejected' => Status::DECLINED,
            'expired' => Status::EXPIRED,
            'voided' => Status::CANCELED,
            'created', 'delivered' => Status::SENT,
            default => null,
        };
    }
}
```

## 4. Production-grade example: `WsSign` + `Client`

For a real HTTP connector, `Model/Provider/WsSign.php` and `Model/Provider/WsSign/Client.php`
are the reference. Hardening every provider should replicate:

- **TLS verification always on** (`CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST`).
- **No redirects followed** (`CURLOPT_FOLLOWLOCATION = false`): a malicious or compromised
  redirect could send tokens or credentials to another host.
- **Tight timeouts** (connect and total), so queue consumers are never blocked.
- **JSON responses validated structurally** before use: the provider is an external trust
  boundary.
- **No secrets in error messages**, logs or exceptions shown to users.
- **Platform URL validated as `https://`** in the application, not only through the HTTP
  client's TLS.

Callback URL, built in `start()`:

```php
$callbackUrl = $baseUrl . 'digitalsignature/callback/index/id/' . (int)$document->getDocumentId()
    . '/token/' . $callbackToken;
```

Every provider must follow this pattern: build the callback URL from the document id and the
`$callbackToken` received by `start()`, and pass it to the external service so it calls back
when the process changes.

Signer data validation is provider-specific (not part of the generic interface): wSign, for
example, validates the email and normalizes the phone number with an international prefix,
falling back to the configurable `default_phone_prefix`.

## 5. Errors: `ProviderException`

```php
class ProviderException extends LocalizedException
{
    public static function retryable(Phrase $phrase, ?\Exception $cause = null): self;
    public static function permanent(Phrase $phrase, ?\Exception $cause = null): self;
    public function isRetryable(): bool;
}
```

There are no subclasses; the two factories carry the distinction:

- `ProviderException::retryable(...)`: network errors, timeouts, HTTP 5xx/429, anything
  transient that is worth retrying automatically.
- `ProviderException::permanent(...)`: invalid data (email/phone), missing configuration, HTTP
  4xx except 429, anything a retry would not fix.

The queue consumer (`Model/Queue/Consumer/ProcessConsumer.php`) retries a `retryable` error up
to `digital_signature/general/max_retries`; a `permanent` error moves the document to `error`
immediately and notifies the admin.

## 6. The HTTP callback flow

`Controller/Callback/Index.php` is **shared by all providers** and deliberately minimal:

- **The callback is a ping, not a payload.** The request body is never read. The only contract
  is the two URL parameters built in `start()`:
  `digitalsignature/callback/index/id/<documentId>/token/<callbackToken>`.
- The token is compared in constant time with the SHA-256 hash stored on the document; the
  plaintext token is never persisted.
- Optional per-provider IP allowlist (`allowed_ips`), checked against `REMOTE_ADDR` only, never
  spoofable headers such as `X-Forwarded-For`.
- When the checks pass, the controller does **not** call the provider. It queues an
  asynchronous refresh, processed by `RefreshConsumer` → `DocumentProcessor::refreshStatus()` →
  **`provider->fetchStatus()`**.
- The response is always a uniform `HTTP 200` (no oracle on id/token validity), and CSRF
  validation is disabled on purpose (server-to-server endpoint).

In practice your service must be able to call the callback URL (GET or POST, body ignored)
when the process status changes. No payload format is required: the callback only triggers a
`fetchStatus()`, which is the single source of truth.

If your service has no callback mechanism at all, the connector still works through the
reconcile cron (`Cron\Reconcile`, every 5 minutes), which refreshes documents idle in `sent`
for more than 15 minutes: slower, but nothing is lost.

## 7. Internal document statuses

`MageOS\DigitalSignature\Model\Document\Status`:

```php
public const PENDING   = 'pending';
public const GENERATED = 'generated';
public const SENT      = 'sent';
public const SIGNED    = 'signed';
public const DECLINED  = 'declined';
public const EXPIRED   = 'expired';
public const ERROR     = 'error';
public const CANCELED  = 'canceled';

public const FINAL_STATES = [self::SIGNED, self::DECLINED, self::EXPIRED, self::CANCELED];
```

`mapStatus()` must always return one of these constants or `null`. Never reference a constant
that does not exist on this class: an unknown status must fall through to `null`, not throw.

`DocumentProcessor::refreshStatus()` applies a transition **only** when the mapped status
differs from the current one **and** is final (`Status::isFinal()`). Intermediate provider
statuses ("waiting for OTP", "in progress") can map to `null` or to `sent`; either way the
document stays `sent` until a final outcome arrives.

`error` is not final: the document can be retried or regenerated.

## 8. Configuration: `system.xml`

Add one group per provider inside the `providers` group of section `digital_signature`:

```xml
<!-- Vendor/AcmeSign/etc/adminhtml/system.xml -->
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Config:etc/system_file.xsd">
    <system>
        <section id="digital_signature">
            <group id="providers">
                <group id="acmesign" translate="label" type="text" sortOrder="50"
                       showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Acme Sign</label>
                    <field id="enabled" translate="label" type="select" sortOrder="10"
                           showInDefault="1" showInWebsite="1" showInStore="1">
                        <label>Enabled</label>
                        <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
                    </field>
                    <field id="api_key" translate="label" type="obscure" sortOrder="20"
                           showInDefault="1" showInWebsite="1" showInStore="1">
                        <label>API Key</label>
                        <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
                        <depends><field id="enabled">1</field></depends>
                    </field>
                    <field id="status_mapping" translate="label" sortOrder="90"
                           showInDefault="1" showInWebsite="1" showInStore="1">
                        <label>Status mapping</label>
                        <frontend_model>MageOS\DigitalSignature\Block\Adminhtml\Form\Field\StatusMapping</frontend_model>
                        <backend_model>Magento\Config\Model\Config\Backend\Serialized\ArraySerialized</backend_model>
                        <depends><field id="enabled">1</field></depends>
                    </field>
                </group>
            </group>
        </section>
    </system>
</config>
```

Rules:

- **Resulting paths**: `digital_signature/providers/acmesign/<field>`. Read them through
  `MageOS\DigitalSignature\Model\Provider\ProviderConfig`: `get()`, `isSetFlag()`,
  `getSecret()` for `Encrypted` fields, `getSerialized()` for `ArraySerialized` fields, and
  `mapConfiguredStatus()` for the status mapping grid. Pass your provider code to each call.
- **Credentials always use** `Magento\Config\Model\Config\Backend\Encrypted`, never plain text
  in the database.
- **Status mapping grid**: reuse the same `frontend_model`/`backend_model`, and call
  `mapConfiguredStatus()` first in `mapStatus()`. Admins can then support a new raw status by
  adding a row, without a code deploy. The grid only offers valid internal statuses.
- **Provider info box** (logo, description, links, legal level): add an `info` field at the
  top of the group whose `frontend_model` extends
  `MageOS\DigitalSignature\Block\Adminhtml\System\Config\ProviderInfo\AbstractProviderInfo`
  (see `Block/Adminhtml/System/Config/ProviderInfo/WsSign.php`):

  ```xml
  <field id="info" sortOrder="5" showInDefault="1" showInWebsite="1" showInStore="1">
      <frontend_model>Vendor\AcmeSign\Block\Adminhtml\System\Config\ProviderInfo\AcmeSign</frontend_model>
  </field>
  ```

  Implement `getLogoUrl()`, `getProviderName()`, `getDescription()`, `getLinks()` and
  `getLegalLevel()`. Reference the logo with
  `$this->getViewFileUrl('Vendor_AcmeSign::images/logo.svg')` from your module's
  `view/adminhtml/web/images/`; this works in developer mode and after
  `setup:static-content:deploy`.

  `getLegalLevel()` is a short label of the signature level offered, in eIDAS terms where
  applicable (e.g. "Advanced Electronic Signature (AES)", "Qualified Electronic Signature
  (QES)", or "No legal validity, testing only"). State only what the provider documents: the
  box always shows a disclaimer asking the merchant to verify the exact certification with the
  provider, and the field is not a compliance guarantee from the module.

Finally, `digital_signature/general/production_ack` (`ProductionModeGuard`) forces **every**
document onto `dummy` until the GDPR acknowledgement is confirmed, whatever provider is
selected. A third-party connector must not bypass it.

## 9. Checklist for a provider module

1. `etc/module.xml` with `<sequence><module name="MageOS_DigitalSignature"/></sequence>`.
2. `etc/di.xml` adding the item to the pool (§2).
3. `Model/Provider/<Name>.php` implementing `SignProviderInterface` (§1), with a hardened HTTP
   client (§4).
4. `etc/adminhtml/system.xml` with the provider group inside `providers` (§8), status mapping
   grid and info box included.
5. `i18n/*.csv` for your strings.
6. Unit tests for `mapStatus()` and the error classification. The core suite in `Test/Unit`
   runs without a Magento installation and is a good template; see [testing.md](testing.md).

None of these steps touches the core module: the whole extension point is
`SignProviderPoolInterface` plus the `di.xml` merge.

## 10. Optional: audit trail certificate

If the service exposes an official certificate or audit report for a completed signature,
implement `MageOS\DigitalSignature\Api\AuditTrailCertificateProviderInterface` on the same
provider class. When the merchant collects audit trail data, the module calls it for every
`signed` document and stores the result (see [audit-trail.md](audit-trail.md)). Providers that
do not implement it get `fetch_status` = `unavailable` and only the module's own hashes.

```php
use MageOS\DigitalSignature\Api\AuditTrailCertificateProviderInterface;
use MageOS\DigitalSignature\Api\Data\DocumentInterface;
use MageOS\DigitalSignature\Exception\ProviderException;
use MageOS\DigitalSignature\Model\Provider\Result\AuditTrailCertificateResult;

class Acme implements SignProviderInterface, AuditTrailCertificateProviderInterface
{
    public function fetchCertificate(DocumentInterface $document): AuditTrailCertificateResult
    {
        $pdf = $this->client->downloadAuditReport($document->getProviderProcessId());
        if ($pdf === '') {
            // Not ready yet on the provider side: the reconcile cron retries it
            throw ProviderException::retryable(__('Acme: audit report not ready.'));
        }

        return new AuditTrailCertificateResult(
            $pdf,                                  // certificate content (stored as a PDF)
            $this->client->signerIp($document),    // signer IP, or null
            null,                                  // authentication method, or null
            $document->getProviderProcessId()      // transaction id, or null
        );
    }
}
```

Throw a retryable `ProviderException` for temporary or not-ready-yet errors (retried up to
`digital_signature/general/max_retries` times) and a permanent one when the certificate will
never be available.
