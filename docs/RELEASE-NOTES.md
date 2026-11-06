Teya Payments for VirtueMart brings Teya Hosted Checkout to Joomla stores using VirtueMart. Version 1.0.0 is the first public release of the integration.

## Payments and refunds

- Connect test and live Teya accounts from the payment method settings. The extension keeps credentials separate and encrypts client secrets and access tokens at rest.
- Send product lines or a single order total to Teya Hosted Checkout. Customers enter their card details on Teya’s payment page.
- Confirm orders from signed Teya webhooks. On checkout return, the extension can also verify the stored session with Teya and confirm a matching successful payment. Both paths share duplicate protection.
- Issue full or partial refunds from the VirtueMart order page. Pending refunds retain the original request reference and idempotency key during recovery.
- Enable Teya customer receipts and API diagnostic logging in the payment method settings. Both options are off by default. Diagnostic logs omit credentials and raw request and response bodies.

## Compatibility

- Joomla 5.4 and 6.x with VirtueMart installed.
- PHP 8.2 or later, subject to Joomla’s PHP requirements. OpenSSL and either cURL or HTTPS streams are required.
- A publicly accessible HTTPS shop and an eligible Teya merchant account.

Automated installation, upgrade, payment confirmation and refund checks passed on Joomla 5.4.8 and 6.1.0 with VirtueMart 4.6.4. The automated payment and refund checks use simulated Teya responses.

## Installation

Upload `pkg_teya_virtuemart-1.0.0.zip` in Joomla’s extension installer. The package installs the payment plugin and its required system companion. The accompanying SHA-256 file verifies the ZIP download.

See the [installation and configuration guide](https://github.com/webensol/teya-payments-for-joomla-virtuemart#installation) for payment method setup and sandbox testing.

Developed by Webensol and distributed under GNU GPL v3.
