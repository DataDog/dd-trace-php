#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

cat <<- "SCANDIR" >$(dirname "$(which php)")/php-without-scan-dir
#!/usr/bin/env bash
php="$(dirname "$0")/php"
if [[ "$@" == *-i* ]]; then
  "$php" "$@" | grep -v "Scan this dir for additional .ini files"
else
  "$php" "$@"
fi
SCANDIR
chmod +x $(dirname "$(which php)")/php-without-scan-dir

# Must be a main php.ini file
echo "" > /tmp/php-empty.ini

# Install using the php installer
new_version="0.74.0"
generate_installers "${new_version}"
php -c /tmp/php-empty.ini ./build/packages/datadog-setup.php --php-bin php-without-scan-dir
assert_ddtrace_version "${new_version}"

ini_file=$(get_php_main_conf)

assert_file_contains "${ini_file}" 'datadog.trace.request_init_hook'
assert_file_contains "${ini_file}" 'datadog.version'

# Removing an enabled property and a commented out property
sed -i 's/datadog\.trace\.request_init_hook.*//g' "${ini_file}"
sed -i 's/datadog\.version.*//g' "${ini_file}"

assert_file_not_contains "${ini_file}" 'datadog.trace.request_init_hook'
assert_file_not_contains "${ini_file}" 'datadog.version'

php ./build/packages/datadog-setup.php --php-bin php-without-scan-dir

assert_file_contains "${ini_file}" 'datadog.trace.request_init_hook'
assert_file_contains "${ini_file}" 'datadog.version'

assert_request_init_hook_exists
