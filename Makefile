.PHONY: package-virtuemart test-virtuemart test-virtuemart-native
package-virtuemart:
	python3 tools/build-packages.py
	cd dist && sha256sum pkg_teya_virtuemart-1.0.0.zip > pkg_teya_virtuemart-1.0.0.zip.sha256

test-virtuemart:
	php tests/virtuemart-regression.php
	php tests/virtuemart-admin-save-regression.php
	php tests/virtuemart-connection-render-regression.php
	php tests/virtuemart-transport-regression.php
	php -d disable_functions=curl_init tests/virtuemart-transport-regression.php
	node tests/virtuemart-refund-ui-regression.cjs
	node tests/virtuemart-admin-ui-regression.cjs

test-virtuemart-native: package-virtuemart
	bash tools/run-virtuemart-native-tests.sh dist/pkg_teya_virtuemart-1.0.0.zip
