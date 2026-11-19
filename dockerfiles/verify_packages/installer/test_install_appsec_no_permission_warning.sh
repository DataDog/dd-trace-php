#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

assert_no_ddtrace

uname=$(uname -a)
arch=$(if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then echo aarch64; else echo x86_64; fi)
version=$(cat VERSION)
package="build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz"

if ! [ -f "${package}" ]; then
    echo "SKIPPED: this test runs only in CI as it requires the .tar.gz at a specific path"
    exit 0
fi

# The package contains appsec/, so the installer copies and chmods appsec/etc.
log_file=$(mktemp)
php ./build/packages/datadog-setup.php --php-bin php --enable-appsec --file "${package}" | tee "${log_file}"

assert_file_not_contains "${log_file}" "Cannot set the permissions"
