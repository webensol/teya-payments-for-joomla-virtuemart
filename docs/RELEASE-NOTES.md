Teya Payments for VirtueMart 1.0.1 includes the GPL v3 notices requested during JED review and the corrected administrator settings save.

The payment method save now updates VirtueMart’s request cache with the server-protected credentials and onboarding state. Earlier cached request values can no longer overwrite those fields. The refund guard uses a clearer private method name to resolve JED Checker’s assertion heuristic.

JED Checker 2.4.4 reports zero errors and all 15 PHP files pass the license notice check. Six warnings remain for manual review. The package README explains the Base64 operations used for credentials and RSA verification, and the POST synchronization required by VirtueMart’s native controller.

Installation, upgrade, payment and refund checks passed on Joomla 5.4.8 and 6.1.0 with VirtueMart 4.6.4.

This release replaces the earlier v1.0.1 package. Sites with v1.0.1 already installed should upload the replacement ZIP through Joomla’s extension installer.

Upload `pkg_teya_virtuemart-1.0.1.zip` to install or upgrade the payment plugin and its required system companion. The accompanying SHA-256 file verifies the package.
