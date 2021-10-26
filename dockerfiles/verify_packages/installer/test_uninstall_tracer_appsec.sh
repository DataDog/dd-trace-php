#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"


extension_dir=$(php_extension_dir)
ini_dir=$(php_conf_dir)
tracer_version="0.67.0"
appsec_version="0.1.0"
php dd-library-php-setup.php --php-bin php \
  --tracer-version $tracer_version --appsec-version $appsec_version
assert_ddtrace_version "${new_version}"

# Uninstall
php dd-library-php-setup.php --php-bin php --uninstall
assert_no_ddtrace
assert_no_ddappsec

# The .so files should be removed
for f in ddtrace.so ddappsec.so; do
  if [ -f "${extension_dir}/$f" ]; then
    echo "Error. File ${extension_dir}/$f should not exist."
    exit 1
  else
    echo "Ok: File ${extension_dir}/$f has been removed."
  fi
done

# The INI files should NOT be removed
for f in 98-ddtrace.ini 98-ddappsec.ini; do
  if [ ! -f "${ini_dir}/$f" ]; then
    echo "Error. File ${ini_dir}/$f should not be removed."
    exit 1
  else
    echo "Ok: File ${ini_dir}/$f has not been removed."

  fi
done

# extension=... in the INI files should be commented out
assert_file_contains "${ini_dir}/98-ddtrace.ini" ";extension = ddtrace.so"
assert_file_contains "${ini_dir}/98-ddappsec.ini" ";extension = ddappsec.so"
