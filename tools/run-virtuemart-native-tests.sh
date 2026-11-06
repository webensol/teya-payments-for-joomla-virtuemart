#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
root_dir="$PWD"
web_root="$(realpath "${TEYA_VM_JOOMLA_ROOT:-$root_dir/www}")"
package_path="${1:-}"
package_mount=()
if [[ -n "$package_path" ]]; then
  package_path="$(realpath "$package_path")"
  [[ -f "$package_path" && "$package_path" == *.zip ]] || { echo 'Provide an existing Joomla package ZIP' >&2; exit 1; }
  package_mount=(--mount "type=bind,src=$package_path,dst=/audit-release.zip,readonly" -e TEYA_AUDIT_PACKAGE=/audit-release.zip)
fi
run_id="teya-vm-audit-$$"
network_name="$run_id"
db_name="$run_id-db"
cleanup() {
  docker rm -f "$db_name" >/dev/null 2>&1 || true
  docker network rm "$network_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT
# Internal network, no host ports, no persistent volume and no merchant database.
docker network create --internal "$network_name" >/dev/null
docker run --pull=never -d --name "$db_name" --network "$network_name" --network-alias teya-vm-audit-db \
  --tmpfs /var/lib/mysql -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
  -e MARIADB_ROOT_HOST=% -e MARIADB_DATABASE=teya_vm_audit mariadb:11.4 >/dev/null
for attempt in $(seq 1 30); do
  if docker exec "$db_name" mariadb-admin --protocol=tcp -h127.0.0.1 ping --silent >/dev/null 2>&1; then break; fi
  sleep 1
done
docker run --pull=never --rm --network "$network_name" --tmpfs /var/www/html --entrypoint sh \
  --mount "type=bind,src=$web_root,dst=/audit-base,readonly" \
  --mount "type=bind,src=$root_dir/plugins,dst=/audit-source-plugins,readonly" \
  --mount "type=bind,src=$root_dir/tests,dst=/audit-tests,readonly" \
  --mount "type=bind,src=$root_dir/packages,dst=/audit-packages,readonly" \
  "${package_mount[@]}" \
  "${TEYA_VM_PHP_IMAGE:-joomla-starter-joomla:latest}" -ec '
    cp -a /audit-base/. /var/www/html/
    cp /audit-tests/fixtures/virtuemart-configuration.php /var/www/html/configuration.php
    if [ ! -f /var/www/html/installation/sql/mysql/base.sql ]; then
      cp -a /usr/src/joomla/installation /var/www/html/
    fi
    # Remove old plugin files only in the disposable test copy.
    rm -rf /var/www/html/plugins/vmpayment/teya /var/www/html/plugins/system/teyavirtuemart
    if [ -z "${TEYA_AUDIT_PACKAGE:-}" ]; then
      mkdir -p /var/www/html/plugins/vmpayment /var/www/html/plugins/system
      cp -a /audit-source-plugins/vmpayment/teya /var/www/html/plugins/vmpayment/
      cp -a /audit-source-plugins/system/teyavirtuemart /var/www/html/plugins/system/
    fi
    php /audit-tests/virtuemart-native-regression.php
  '
