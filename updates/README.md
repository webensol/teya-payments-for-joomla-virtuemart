# Joomla update feed

`virtuemart.xml` is the feed registered by the installer. It lists only published releases.

For each release, download the public ZIP without authentication and verify its SHA-256 before updating the feed. Update the version, download URL, release notes link and checksum together. Fetch the feed without authentication after pushing and check that its download URL returns the same ZIP.
