# Joomla update feed

`virtuemart.xml` is the feed registered by the installer. It lists only published releases. `virtuemart-1.0.0.xml` contains the entry prepared for the first release.

After the repository and release are public, download the release ZIP without authentication and check its SHA-256 against both the release checksum and the prepared entry. Replace the contents of `virtuemart.xml` with the verified entry and check that Joomla can fetch the feed.
