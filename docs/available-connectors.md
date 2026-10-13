# Available signature connectors

`MageOS_DigitalSignature` ships four connectors in the core module. Each one is configured
under **Stores > Configuration > Mage-OS > Digital Signature > Signature providers** and
selected with `digital_signature/general/provider`. Configuration fields are listed in
[triggers-and-configuration.md](triggers-and-configuration.md#providers).

| Code | Provider | Signature level declared | Status updates |
|---|---|---|---|
| `wssign` | wSign by EuroNovate (recommended) | Advanced Electronic Signature (AES), OTP via SMS or email | Callback + reconcile polling |
| `docusign` | DocuSign eSignature (JWT, REST v2.1) | SES, AES, QES | Callback (`eventNotification`) + reconcile polling |
| `adobesign` | Adobe Acrobat Sign (OAuth refresh token) | SES, AES, QES | Reconcile polling |
| `dummy` | Built-in simulator | No legal validity, test/development only | Reconcile polling |

The legal level is the one declared by each provider. The exact certification depends on the
plan and configuration of the merchant's account: verify it with the provider.

## wSign (`wssign`)

Advanced electronic signature platform by EuroNovate. The connector uploads the PDF generated
from the template, starts the signing request with OTP via SMS or email, and receives status
updates through an authenticated callback. Requires a wSign account (platform URL, tenant,
owner user and password) from EuroNovate. API notes: [wssign-api.md](wssign-api.md).

## DocuSign (`docusign`)

Sends envelopes with the contract PDFs through the eSignature REST API, authenticated with JWT
impersonation (integration key, user id, RSA private key). Set
`digital_signature/providers/docusign/environment` to `production` for live envelopes: the
default `demo` sends everything to the developer sandbox.

## Adobe Acrobat Sign (`adobesign`)

Sends agreements through the Acrobat Sign REST API, authenticated with an OAuth refresh token,
on the API access point (shard) of the merchant's account. Status changes are picked up by the
reconcile cron.

## Dummy (`dummy`)

Contacts no external service: it reports a sent document as signed after
`auto_sign_delay` seconds and returns the original PDF as the "signed" one. Use it to test
templates, triggers, notifications and the customer area. It must be enabled explicitly with
`digital_signature/providers/dummy/allow`, and it is the provider every document is forced
onto until `digital_signature/general/production_ack` is confirmed.

## Status mapping

Every connector reads the admin **Status mapping** grid before its built-in mapping, so a new
raw status returned by a provider can be mapped to an internal status without a deploy. See
[troubleshooting.md](troubleshooting.md#unmapped-provider-status).

## Another provider

Connectors for other services (Namirial, InfoCert, Yousign, regional providers) can be built
as separate modules without changing the core: see
[sign-provider-integration.md](sign-provider-integration.md).
