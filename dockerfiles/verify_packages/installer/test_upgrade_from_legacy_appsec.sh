#!/usr/bin/env sh

set -e

. "$(dirname "$0")/utils.sh"

assert_no_ddtrace

# A released package ships AppSec as the separate ddappsec extension, which the
# installer enables with an extension line next to ddtrace's.
old_version="1.25.1"
fetch_setup_for_version "$old_version" /tmp
run_released_installer /tmp/datadog-setup.php --php-bin php --enable-appsec
ini_file="$(get_php_conf_dir)/98-ddtrace.ini"
extension_dir="$(get_php_extension_dir)"
assert_ddtrace_version "$old_version"
assert_appsec_version "$old_version"
assert_appsec_enabled
assert_file_exists "${extension_dir}/ddappsec.so"
php -r 'exit(extension_loaded("ddappsec") ? 0 : 1);'

# Upgrade without repeating --enable-appsec. ddtrace now includes AppSec and
# refuses to start next to ddappsec, so the legacy extension must be disabled
# and removed while AppSec stays enabled.
php ./build/packages/datadog-setup.php --php-bin php
version=$(cat VERSION)
assert_ddtrace_version "$version"
assert_appsec_version "$version"
assert_file_contains "$ini_file" "; extension = ddappsec.so"
if grep -Eq '^[[:space:]]*extension[[:space:]]*=.*ddappsec' "$ini_file"; then
    echo "Error: the legacy ddappsec extension is still enabled in '$ini_file'"
    cat "$ini_file"
    exit 1
fi
echo "Ok: the legacy ddappsec extension is disabled in '$ini_file'"
assert_file_not_exists "${extension_dir}/ddappsec.so"
php -r 'exit(extension_loaded("ddappsec") ? 1 : 0);'

assert_file_contains "$ini_file" "datadog.appsec.enabled = On"
php -r 'exit(filter_var(ini_get("datadog.appsec.enabled"), FILTER_VALIDATE_BOOLEAN) ? 0 : 1);'
assert_appsec_enabled
