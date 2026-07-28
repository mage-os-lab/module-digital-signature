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

## Why agencies choose this module

- **A sellable feature, not just a technical add-on.** Digital signature on orders/contracts
  (B2B, financing, rentals, warranties) is something you can resell to your own clients as a
  service, not only a plugin you install.
- **No vendor lock-in.** The provider integration is pluggable: WsSign is the native, recommended
  connector, DocuSign and Adobe Sign are already implemented, and a documented pattern lets you
  build a custom connector for any regional/local provider — see the
  [sign provider integration guide](docs/sign-provider-integration.md). If a client's signature
  vendor changes, you don't rewrite the integration.
- **eIDAS compliance out of the box.** Each provider exposes its legal signature level (Simple/
  Advanced/Qualified) directly in the admin, with a disclaimer — a real objection-handler in
  front of legally cautious clients, especially in the EU/Italian market.
- **GDPR by design.** A configurable retention cron deletes PDFs and personal data (email/phone)
  of closed documents past the retention period, keeping only the audit trail — an easy line in a
  commercial proposal.
- **Low maintenance risk.** The suite has 240+ automated unit tests and runs standalone without a
  full Magento installation, which means fewer silent breakages after a Magento/dependency
  upgrade and fewer non-billable support hours.
- **End-client UX already built.** A visual PDF builder (click-to-place signature tag), a document
  statistics dashboard and automatic signature reminders are features you'd otherwise have to
  build from scratch to justify the price to a client.
- **Multi-language ready.** All strings are translated into 8 locales (it_IT, en_US, es_ES, fr_FR,
  de_DE, nl_NL, pt_BR, zh_Hans_CN), which matters for agencies serving multi-market clients.
- **Try before you commit.** The bundled `Dummy` provider simulates a full signature flow locally,
  with no real credentials, so you can evaluate or demo the module end-to-end before configuring
  a real provider.

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

## Running the test suite

The unit test suite runs standalone, without a full Magento installation (a set of minimal
framework stubs under `tests/stubs/` stands in for `magento/framework` and friends). The
module's own `composer.json` requires `magento/*` packages from the private
`repo.magento.com` repository, so it cannot be installed on its own for this purpose. Use the
dedicated test manifest instead:

```
COMPOSER=composer.test.json composer install
vendor/bin/phpunit
```

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
