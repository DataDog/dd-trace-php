#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# 0.75.0 doesn't exist on arm
uname=$(uname -a)
arch=$(if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then echo aarch64; else echo x86_64; fi)
if [ "${arch}" = "aarch64" ]; then
  exit 0
fi

version=$(cat VERSION)
if ! [ -f "./build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz" ]; then
  echo "SKIPPED: this test runs only in CI as it requires the .tar.gz at a specific path"
  exit 0
fi

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"


# Get a relased version of the setup script. I've picked a recent one that at
# this time doesn't have the switch from zend_extension to extension for the
# profiling module.
released_version="0.75.0"
fetch_setup_for_version "$released_version" "/tmp"
php /tmp/datadog-setup.php --php-bin php
rm -v /tmp/datadog-setup.php

assert_ddtrace_version "${released_version}"

# Uninstall with new version, since it seems likely users will attempt this.
# This gives us a heads up if it breaks somehow.
php datadog-setup.php --php-bin php --uninstall
assert_no_ddtrace
assert_no_appsec
assert_no_profiler

# Lastly, re-install with profiling.
php ./build/packages/datadog-setup.php --enable-profiling --php-bin php --file "./build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz"

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
for extension in ddtrace datadog-profiling ; do
    if [ -f "${extension_dir}/${extension}.so" ]; then
        echo "Ok: File ${extension_dir}/${extension}.so exists."
    else
        echo "Error. File ${extension_dir}/${extension}.so should exist."
        exit 1
    fi
done

assert_ddtrace_version "${version}"
assert_profiler_version "${version}"
