# Testing

## Unit tests without Magento

The unit tests live in `Test/Unit` (Magento convention) and run **without a Magento
installation**: `tests/bootstrap.php` loads minimal framework stubs (`tests/stubs/MagentoStubs.php`)
only when the real Magento classes are not autoloadable. Inside a Magento project the stubs
are skipped.

The module's `composer.json` requires `magento/*` packages, so in a clone of the module
repository use the dedicated test manifest:

```bash
COMPOSER=composer.test.json composer install
vendor/bin/phpunit
```

Test doubles shared by the suite are in `TestSupport/`: `FakeDocument` (in-memory
`DocumentInterface`), `FakeCurl` and `SequencedCurlFactory` (Magento HTTP client doubles that
return scripted responses).

The suite has 312 tests and runs on PHP 8.1 – 8.5.

### What is covered

- PDF handling: signature tag parsing and replacement through incremental updates, classic and
  stream cross-reference tables (real PDF fixtures included), template validation, merge
  fields.
- Triggers: server-side enforcement of the customer's choice, trigger resolution chain
  (product override → template → default), child lines skipped, deduplication.
- Document lifecycle: generation, processing, regeneration, reminder eligibility, quota
  calculation and blocking.
- Providers: wSign, DocuSign and Adobe Sign connectors and HTTP clients (error
  classification, status mapping, environment warning), dummy provider.
- REST API store scoping, outbound webhooks (payload, HMAC signature, delivery, backoff), XLSX
  export, layout regression (cart checkbox container), composer dependency constraints.

## Writing tests for your own module

Satellite modules (custom providers, merge fields) can reuse the same approach: depend on
`phpunit/phpunit` and on this module, mock `DocumentInterface` or use `FakeDocument`, and
test `mapStatus()` and the error classification (`ProviderException::retryable()` vs
`permanent()`) of your connector. See
[sign-provider-integration.md](sign-provider-integration.md#9-checklist-for-a-provider-module).
