#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

uname=$(uname -a)
arch=$(if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then echo aarch64; else echo x86_64; fi)
version=$(cat VERSION)

if ! [ -f "build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz" ]; then
    echo "SKIPPED: this test runs only in CI as it requires the .tar.gz at a specific path"
    exit 0
fi

# Install using the php installer
php ./build/packages/datadog-setup.php --php-bin php --file "build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz"

# Just check installation, not the version as it is not deterministic.
if [ -z "$(php -m | grep ddtrace)" ]; then
    echo "\nError: ddtrace is not installed\n---\n$(php -v)\n---\n"
    exit 1
else
    echo "\nOk: ddtrace is installed\n"
fi

assert_file_exists /opt/datadog/dd-library/*/dd-trace-sources/src/bridge/_files_api.php

assert_sources_path_exists
