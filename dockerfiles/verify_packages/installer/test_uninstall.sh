#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

extension_dir="$(php -i | grep '^extension_dir' | awk '{ print $NF }')"
ini_dir="$(php -i | grep '^Scan' | awk '{ print $NF }')"

appsec=$(is_appsec_installable && echo 1 || true)

# Install using the php installer
version="0.79.0"
sha256sum="35532a2b78fae131f61f89271e951b8c050a459e7d10fd579665c2669be8fdad"
destdir="/tmp"
fetch_setup_for_version "$version" "$sha256sum" "$destdir"
php "$destdir/datadog-setup.php" --php-bin php --enable-profiling $([ -n "$appsec" ] && echo --enable-appsec)
rm -v "$destdir/datadog-setup.php"
assert_ddtrace_version "${version}"
if [ -n "$appsec" ]; then
  assert_appsec_version "0.4.0"
fi
assert_profiler_version "0.10.0"

# Uninstall
php ./build/packages/datadog-setup.php --php-bin php --uninstall

assert_no_ddtrace
assert_no_appsec
assert_no_profiler

# The .so files should be removed
if [ -f "${extension_dir}/ddtrace.so" ]; then
    echo "Error. File ${extension_dir}/ddtrace.so should not exist."
    exit 1
else
    echo "Ok: File ${extension_dir}/ddtrace.so has been removed."
fi
if [ -f "${extension_dir}/datadog-profiling.so" ]; then
    echo "Error. File ${extension_dir}/datadog-profiling.so should not exist."
    exit 1
else
    echo "Ok: File ${extension_dir}/datadog-profiling.so has been removed."
fi
if [ -n "$appsec" ] && [ -f "${extension_dir}/ddappsec.so" ]; then
    echo "Error. File ${extension_dir}/ddappsec.so should not exist."
    exit 1
else
    echo "Ok: File ${extension_dir}/ddappsec.so has been removed."
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
assert_file_contains "${ini_dir}/98-ddtrace.ini" ";extension = datadog-profiling.so"
if [ -n "$appsec" ]; then
  assert_file_contains "${ini_dir}/98-ddtrace.ini" ";extension = ddappsec.so"
fi
