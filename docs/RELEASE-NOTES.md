Teya Payments for VirtueMart 1.0.1 includes the GPL v3 notices requested during JED review and the corrected administrator settings save.

The payment method save updates VirtueMart’s request cache with the server-protected credentials and onboarding state. It uses the native request API and validates the submitted settings array.

Credential and webhook encoding now use the ParagonIE library bundled with Joomla. Existing encrypted credentials retain the same format, encryption algorithm and field binding.

JED Checker 2.4.4 reports zero errors and zero warnings. Installation, upgrade, settings preservation, payment and refund tests passed on Joomla 5.4.8 and 6.1.0 with VirtueMart 4.6.4.

This package replaces the earlier v1.0.1 download. Sites with v1.0.1 already installed should upload the replacement ZIP through Joomla’s extension installer.

Upload `pkg_teya_virtuemart-1.0.1.zip` to install or upgrade the payment plugin and its required system companion. The accompanying SHA-256 file verifies the package.
