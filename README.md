# MageOS Digital Signature for Magento 2 / Mage-OS

Digital signature of order contracts through external providers (WsSign and others)

---

## Overview

The **MageOS_DigitalSignature** module generates the contractual document for an order from a
customizable PDF template and hands it off to an external digital signature provider (e.g.
[WsSign](https://www.firmadigitale.com/)), tracking its status through signing, rejection or
expiry. Customers can opt in to the signature from the cart, minicart and checkout; admins
manage templates, documents and providers from a dedicated backend panel.

The core module (`src/`) works with the **Luma** theme. For **Hyvä** (Tailwind/Alpine) stores,
the `src-hyva/` satellite module is available: it only replaces the frontend templates and
reuses the same backend.

## Features

- PDF document generation from a template with dynamic placeholders, validated on upload.
- Configurable automatic triggers (order placed, invoice paid/created) plus bulk generation from
  the admin order grid.
- Pluggable integration with external digital signature providers (WsSign included, plus a
  "dummy" provider for testing/development); see the
  [third-party sign provider integration guide](docs/sign-provider-integration.md).
- Asynchronous processing via message queue (`queue_consumer.xml`/`queue_publisher.xml`) for
  document generation and status refresh, with a reconciliation cron.
- Email notifications to the customer (document ready/signed/expired-rejected) and to the admin
  on final error, with configurable templates.
- Signature opt-in in cart, minicart and checkout, for both Luma and Hyvä themes, with ARIA
  support for accessibility.
- Retention/cleanup cron for documents and a dedicated production-mode gate (GDPR compliance).
- Admin panel for template and document management, provider configuration, with dedicated ACL
  resources and menu entries.

## Installation

1. Install the core module into your Magento 2 / Mage-OS project with composer:
    ```
    composer require mage-os/module-digital-signature
    ```
2. If your store uses the **Hyvä** theme, also install the frontend satellite:
    ```
    composer require mage-os/module-digital-signature-hyva
    ```
   Note: `hyva-themes/magento2-theme-module` requires a valid Hyvä license and access to their
   private Composer repository, which is not bundled with this package.

   **Not published yet**: the `mage-os/module-digital-signature-hyva` repository/package does
   not exist online yet. It will be requested and published once the core module above is
   released; until then the Hyvä satellite only lives as the `src-hyva/` folder in this
   monorepo.
3. Enable the modules:
    ```
    bin/magento setup:upgrade
    ```

## Configuration

Configuration lives under **Stores > Configuration > Mage-OS > Digital Signature**:

- **General**: module enable toggle, GDPR acknowledgement for production mode, default
  generation trigger, active signature provider, maximum retry attempts and the document limit
  per reconciliation run.
- **Email notifications**: sender, admin recipient, and templates for each event (document
  ready, signed, expired/rejected, error).

PDF templates and their placeholders are managed under **Digital Signature > Document
Templates** in the admin panel; generated documents and their status under **Digital Signature >
Signed Documents**.

For technical details see the [`docs/`](docs) folder (functional analysis, WsSign API, testing,
security audit).

## Known limitations

- **Encrypted PDFs and PDFs using object streams (a PDF 1.5+ compression feature) are rejected at
  upload**, with an explicit error message. This is the most common cause of an unexpected
  rejection: **Adobe Acrobat's default export settings enable object-level compression**, as do
  many modern LaTeX distributions. If your template is rejected, disable "Object Level
  Compression" (or equivalent) in your PDF exporter's settings and re-export. **LibreOffice,
  Microsoft Word and Google Docs export PDFs without object streams by default**, so templates
  produced by those tools normally work without any changes. Cross-reference *streams* (a
  different PDF 1.5+ feature, also common) are fully supported.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](src/LICENSE) for more information.
