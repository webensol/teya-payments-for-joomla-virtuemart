Teya Payments for VirtueMart

Install this complete package through Joomla's extension installer. It contains
the VirtueMart payment plugin and the system companion. Install and configure
VirtueMart before uploading this package.

Installation, requirements and support
https://github.com/webensol/teya-payments-for-joomla-virtuemart

JED Checker review notes

The Base64 operations in TeyaVirtuemartCredentials.php store and read the binary
AES-256-GCM IV, authentication tag and ciphertext in the existing credential
format. Decoded bytes are authenticated and decrypted before use.

TeyaVirtuemartUtils.php converts Teya's Base64 DER public signing key to PEM.
TeyaVirtuemartWebhookVerifier.php decodes the RSA signature for openssl_verify().
These routines process cryptographic data and do not execute decoded PHP.

The system companion writes server-protected settings to POST because
VirtueMart's vRequest::getPost() reads POST directly. The write follows
administrator ACL and CSRF checks and retains credentials from the database.
vRequest::setVar() updates the native request cache used by getHtml().

Framework compatibility findings concern legacy Joomla interfaces required by
the supported VirtueMart version. The package supplies a compatibility bridge.
