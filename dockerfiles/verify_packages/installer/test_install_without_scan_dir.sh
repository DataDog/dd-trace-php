#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

# Must be a main php.ini file
echo "" > /tmp/php-empty.ini

cat <<- "SCANDIR" >$(dirname "$(which php)")/php-without-scan-dir
#!/usr/bin/env bash
php="$(dirname "$0")/php"
if [[ "$@" == *-i* ]]; then
  "$php" -c /tmp/php-empty.ini "$@" | grep -v "Scan this dir for additional .ini files"
else
  "$php" -c /tmp/php-empty.ini "$@"
fi
SCANDIR
chmod +x $(dirname "$(which php)")/php-without-scan-dir

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --php-bin php-without-scan-dir
assert_ddtrace_version "${version}" php-without-scan-dir

ini_file=$(get_php_main_conf php-without-scan-dir)

assert_file_contains "${ini_file}" 'datadog.trace.sources_path'
assert_file_contains "${ini_file}" 'datadog.version'

# Removing an enabled property and a commented out property
sed -i 's/datadog\.trace\.sources_path.*//g' "${ini_file}"
sed -i 's/datadog\.version.*//g' "${ini_file}"

assert_file_not_contains "${ini_file}" 'datadog.trace.sources_path'
assert_file_not_contains "${ini_file}" 'datadog.version'

php ./build/packages/datadog-setup.php --php-bin php-without-scan-dir

assert_file_contains "${ini_file}" 'datadog.trace.sources_path'
assert_file_contains "${ini_file}" 'datadog.version'

assert_sources_path_exists php-without-scan-dir
