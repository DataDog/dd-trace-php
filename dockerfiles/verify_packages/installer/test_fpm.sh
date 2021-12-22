#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"

# Install using the php installer
new_version="0.68.0"
php dd-library-php-setup.php --php-bin php-fpm --version "${new_version}"
assert_ddtrace_version "${new_version}"

# Uninstall
php dd-library-php-setup.php --php-bin php-fpm --uninstall
assert_no_ddtrace

# The .so file should be removed
if [ -f "${extension_dir}/ddtrace.so" ]; then
    echo "Error. File ${extension_dir}/ddtrace.so should not exist."
    exit 1
else
    echo "Ok: File ${extension_dir}/ddtrace.so has been removed."
fi

# The INI file should NOT be removed
if [ ! -f "${ini_dir}/98-ddtrace.ini" ]; then
    echo "Error. File ${ini_dir}/98-ddtrace.ini should not be removed."
    exit 1
else
    echo "Ok: File ${ini_dir}/98-ddtrace.ini has not been removed."
fi

# extension=... in the INI file should be commented out
assert_file_contains "${ini_dir}/98-ddtrace.ini" ";extension = ddtrace.so"
