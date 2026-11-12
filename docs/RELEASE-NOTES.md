Teya Payments for VirtueMart 1.0.2 improves administrator settings saves and completes the changes requested during Joomla Extensions Directory review.

## Changes

- Protected credentials and onboarding state are preserved through VirtueMart’s native request API when a payment method is saved. Cached request values are refreshed before the controller stores the settings.
- Credential, public-key and signature encoding use the ParagonIE library bundled with Joomla. Existing AES-256-GCM credential envelopes retain the same format and field binding.
- PHP files include GNU GPL v3 notices. JED Checker 2.4.4 reports zero errors and zero warnings. Separate framework compatibility notices remain for legacy Joomla interfaces used by VirtueMart.

## Installation and upgrade

Upload `pkg_teya_virtuemart-1.0.2.zip` through Joomla’s extension installer, or install the update offered by Joomla. The package installs or upgrades the payment plugin and its required system companion. The accompanying SHA-256 file verifies the download.

Requirements are Joomla 5.4 or 6.x with VirtueMart installed, PHP 8.2 or later subject to Joomla’s requirements, OpenSSL, an HTTPS transport, a publicly accessible HTTPS shop, and an eligible Teya merchant account.

## Verification

Fresh installations and upgrades from 1.0.1 passed on Joomla 5.4.8 and 6.1.0 with VirtueMart 4.6.4, preserving credentials and refund data. The automated suite includes 170 payment and security checks, native settings saves, signed payment notifications, duplicate handling, partial/full refunds, and CLI recovery. Automated payment and refund checks use simulated Teya responses.

Developed by Webensol and distributed under GNU GPL v3.
