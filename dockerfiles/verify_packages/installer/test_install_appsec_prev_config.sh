#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace/ddappsec
assert_no_ddtrace
assert_no_ddappsec

ini="$(php_conf_dir)"/98-ddappsec.ini
echo 'extension=ddappsec.so' >> "$ini"
echo 'ddappsec.helper_path=/foo/bar' >> "$ini"

appsec_version=0.1.0
php dd-library-php-setup.php --php-bin php --no-trace --appsec-version 0.1.0

assert_ddappsec_installed

if grep -q '^ddappsec\.helper_path\s\?=\s\?"/opt/' "$ini" && ! grep -q foo/bar "$ini"; then
  echo "OK: ddappsec.helper_path correctly changed"
else
  echo "---\nError: No correct value for ddappsec.helper_path\n---\nContent follows:\n$(cat "$ini")\n---\n"
  exit 1
fi

if grep -q '^ddappsec\.rules_path\s\?=\s\?"/opt/' "$ini"; then
  echo "OK: ddappsec.rules_path correctly added"
else
  echo "---\nError: No correct value for ddappsec.rules_path\n---\nContent follows:\n$(cat "$ini")\n---\n"
  exit 1
fi
