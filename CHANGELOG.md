# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

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
