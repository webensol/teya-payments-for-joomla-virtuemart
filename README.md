# Teya Payments for VirtueMart

Accept card payments in VirtueMart through Teya Hosted Checkout. Customers enter their card details on Teya’s payment page. The extension confirms orders from signed payment webhooks or a verified checkout return and supports full and partial refunds from the order page.

## Requirements

- Joomla 5.4 or 6.x with VirtueMart installed and configured. Tested with VirtueMart 4.6.4.
- PHP 8.2 or later, subject to Joomla’s PHP requirements, with OpenSSL and either cURL or HTTPS streams enabled.
- A publicly accessible HTTPS shop and an eligible Teya merchant account.

## Installation

1. Download `pkg_teya_virtuemart-1.0.0.zip` from the [GitHub releases page](https://github.com/webensol/teya-payments-for-joomla-virtuemart/releases).
2. In Joomla administration, open **System → Install → Extensions** and upload the package. It installs and enables the VirtueMart payment plugin and its required system companion.
3. In **VirtueMart → Shop → Payment Methods**, create a payment method using **Teya Payments - VirtueMart** and save it.
4. Select **Test**, click **Connect to Teya**, and complete onboarding. Confirm that the settings show the test connection.
5. Run a test payment and refund. When ready, connect the live environment separately, save the method in live mode and verify the storefront payment method.

Upload the complete package when upgrading manually. Joomla’s extension updater uses the update site included in the package.

## Payment and refund settings

- Test and live credentials are stored separately. Client secrets and access tokens are encrypted using the Joomla site secret. Retain the site secret with your backup and protect access to both.
- Choose whether to send product lines or a single order total. HUF orders must have whole-forint totals.
- Teya’s customer payment email is disabled by default. Configure VirtueMart’s order emails in VirtueMart.
- Open a paid order and use **Refund via Teya**. Enter a smaller amount for a partial refund or use the remaining balance for a full refund. Changing the order to the configured refund status also requests a refund of the remaining balance.
- Check pending refunds in the order panel. To process existing pending requests from a scheduler, run this command from the Joomla root:

  ```sh
  php plugins/vmpayment/teya/cli/refunds.php --site-url=https://your-shop.example
  ```

  Use `--check` to check CLI prerequisites before enabling the schedule.
- Disconnect all connected test and live payment methods before uninstalling the package. Resolve pending refunds before disconnecting a method.

## Support and development

Include your Joomla, VirtueMart and PHP versions when reporting an issue. Remove credentials, tokens and customer data from examples. [GitHub issues](https://github.com/webensol/teya-payments-for-joomla-virtuemart/issues)

Run `make package-virtuemart` with Python 3.9 or later to build the ZIP and SHA-256 checksum in `dist/`. Run `make test-virtuemart` with PHP and Node.js for the offline regression suite.

The native tests also require Docker, a local Joomla/PHP image and a Joomla/VirtueMart test site file tree. Set `TEYA_VM_JOOMLA_ROOT` to the test site files and `TEYA_VM_PHP_IMAGE` to the local image, then run `make test-virtuemart-native`. The runner uses a disposable MariaDB 11.4 database and simulated Teya responses.

Developed by Webensol and distributed under the [GNU General Public License v3](LICENSE.txt). The extension is free to download. Teya’s payment service terms apply.
