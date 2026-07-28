# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0]
### Added
- **REST API for signature documents**: `getById`/`getList` (with standard search criteria) plus
  `generate`/`regenerate` actions exposed via `webapi.xml`, all secured by the module's own ACL
  resources.
- **Multi-store API scoping ("API Integrations")**: Magento integrations can be mapped to a
  restricted set of stores (`ApiConsumer` entity + admin CRUD); any REST caller resolved as an
  unmapped or disabled integration is fail-closed (sees no documents, cannot generate for
  out-of-scope orders) via `ApiConsumerScope` and a `DocumentRepositoryInterface` plugin.
- **Outbound webhooks**: a `WebhookSubscription` entity (admin CRUD) lets external systems
  subscribe to document status changes; each transition dispatches a Magento event, queues one
  signed HTTP delivery per active subscription (HMAC-SHA256, re-signed with the current secret on
  every attempt), and retries failed deliveries with exponential backoff (1'/5'/30'/2h) via a
  dedicated consumer and cron job. Deliveries that exhaust all attempts are marked failed and
  trigger an admin notification email. The signature is sent in the `X-Signature` header
  (`sha256=<hex>`), the JSON payload carries a `delivery_id` for receiver-side deduplication,
  delivery rows are claimed atomically (`sending` status) so parallel consumers never send the
  same POST twice, and permanent HTTP errors (4xx other than 408/429) fail immediately instead of
  burning the remaining retries. Target URLs are restricted to the `http`/`https` schemes.

### Changed
- **System-wide English codebase standardization**: all inline code comments, docblocks, system XML configuration labels/comments, exception/log messages, UI component labels, email templates, and primary translation keys (`__()`/`$t()`) have been standardized to English across the entire module. Updated `src/i18n/*.csv` (including `it_IT.csv` and all foreign locales) to use English source keys as the primary translation dictionary.

## [0.2.0]
### Added
- **PDF 1.5+ cross-reference stream support**: a custom parser (`ClassicXrefReader`, `XrefStreamReader`,
  `XrefChainResolver`, `DictFields`, `StartxrefLocator`) reads both classic and compressed
  xref tables, with real LibreOffice-generated PDF fixtures for round-trip testing. On-demand
  preview of the processed template is now available from the admin template form.
- **Precise-coordinate signature tag injection** (`SignatureTagInjector`): places the signature
  tag at exact page coordinates on both flat and nested page trees, supports indirect
  `/Resources` references, falls back to a dedicated embedded Helvetica font when the page has
  none (avoiding subset fonts that can't render the tag's characters), and prints the tag in a
  configurable color (invisible by default).
- **Signature reminders**: automatic reminder/escalation emails for documents awaiting signature,
  with a dedicated `EligibilityCalculator`, `SignatureReminder` cron job, new DB columns and
  config group, and translations for all supported locales.
- **eIDAS legal signature level**: each provider now exposes its legal level (e.g. Simple/Advanced/
  Qualified) with a disclaimer shown in the admin provider configuration block.
- **Available connectors catalog**: a DTO/model/block/template listing the signature providers
  that can be integrated, with cross-linked documentation.
- **Dynamic merge fields**: order and invoice data (order number, grand total, customer name,
  order/invoice date) can be substituted directly into PDF templates.
- **Document stats panel**: a live, DB-backed admin dashboard (5 summary cards) on document
  volumes and outcomes over a configurable period.
- **Visual PDF builder** (admin): a pdf.js-based frontend to preview a template and place the
  signature tag by clicking on the page, with a dedicated CSP collector, upload/preview/inject-tag
  controllers, and coordinate conversion between screen and PDF space.
- **DocuSign provider**: JWT Server-to-Server authentication (with session caching), envelope
  creation via AutoPlace anchor tab (`{WSIGN#`), status polling, signed PDF download and envelope
  cancellation (void).
- **Adobe Sign provider**: OAuth refresh-token authentication (with session caching), transient
  document upload, agreement creation/status polling/cancellation, signed PDF download and
  status mapping.
- Unit test suite for the DocuSign provider (`Docusign`/`Docusign\Client`, 39 tests), covering
  the JWT auth flow (cache hit/miss/corrupt, incomplete config, invalid key, account selection,
  demo/production environment), envelope lifecycle and HTTP error classification.
- Unit test suite for the Adobe Sign provider (`AdobeSign`/`AdobeSign\Client`, 35 tests), covering
  the OAuth refresh-token flow (cache hit/miss/corrupt, incomplete config), agreement lifecycle
  and HTTP error classification.
- The unit test suite can now be run standalone directly from this repository, without a full
  Magento installation: see `composer.test.json` and the "Running the test suite" section in the
  README.

### Fixed
- Ambiguous `created_at` column in the document statistics aggregation query.
- `Docusign::start()` passed a `Phrase` object to `sprintf()` instead of a string, causing a
  PHP type error.
- `Docusign::mapStatus()` referenced non-existent `Status` constants
  (`STATUS_IN_PROGRESS`/`STATUS_SIGNED`/`STATUS_REJECTED`/`STATUS_EXPIRED`), causing a fatal
  error on every real status returned by DocuSign during polling.
- Non-AMD loading of pdf.js and an admin fieldset switcher error in the visual PDF builder.
- File uploader mixin dropped the original upload response data (e.g. `files`) in its success
  callback.

## [0.1.0]
### Added
- Generation and digital signature of order contract documents through external providers
  (WsSign, plus a dummy provider for testing).
- PDF template management with dynamic placeholders and validation.
- Automatic triggers on order placed and invoice paid/created, plus bulk generation from the
  admin order grid.
- Asynchronous message queue processing for document generation and status refresh.
- Email notifications to the customer about the signature outcome.
- Digital signature opt-in in cart, minicart and checkout, for both the Luma and Hyvä themes.
- Dedicated customer-data section for minicart updates.
- Admin panel for templates, documents and provider configuration, with dedicated ACL resources
  and menu entry.
- Retention/cleanup cron for documents and a status reconciliation cron.
- PHPUnit test suite and CI pipeline (PHP lint, XML validation, unit tests, Magento Coding
  Standard) across a PHP 8.1-8.5 matrix.
- Technical guide for developing third-party signature providers.
- Translations for the main languages supported by Mage-OS.

### Changed
- Module license switched to MIT for open source distribution on the Mage-OS repository.
- Declared PHP compatibility extended to 8.1-8.5.
- Dependencies on core Magento modules declared explicitly in `composer.json` and `module.xml`,
  in line with the code's actual usage.
- Renamed the project for official distribution under the `mage-os-lab/module-digital-signature`
  repository: vendor/namespace changed from `Digitalway`/`Digitalway_FirmaDigitale` to
  `MageOS`/`MageOS_DigitalSignature` (and the Hyvä satellite to `MageOS_DigitalSignatureHyva`),
  Composer packages moved to `mage-os/module-digital-signature(-hyva)`, the frontName/route moved
  from `firmadigitale` to `digitalsignature`, config paths moved from `firmadigitale/*` to
  `digital_signature/*`, and the database tables/columns/indexes were renamed accordingly
  (`mageos_digitalsignature_*`).

### Fixed
- Decompression bomb in the PDF template parser.
- PDF template upload blocked by a core Media Gallery plugin.
- Invalid XML in `sales_order_grid.xml` (massaction nested inside `columns`).
- ARIA accessibility of the signature opt-in components in cart/minicart/checkout.

### Security
- Added a dedicated production-mode gate and document retention policy (GDPR compliance).
- Fixed IDOR and CSRF vulnerabilities on the signature provider callback controllers.
