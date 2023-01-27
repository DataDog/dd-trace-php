#!/bin/bash
set -eu

echo "Loading install script"
curl -Lf -o /tmp/dd-library-php-setup.php \
  https://raw.githubusercontent.com/DataDog/dd-appsec-php/installer/dd-library-php-setup.php

cd /binaries

INSTALLER_ARGS=(--tracer-file /binaries/datadog-php-tracer*.tar.gz --appsec-file /binaries/dd-appsec-php-*.tar.gz)

PHP_INI_SCAN_DIR="/etc/php" php /tmp/dd-library-php-setup.php \
    "${INSTALLER_ARGS[@]}"\
    --php-bin all

export DD_APPSEC_ENABLED=1

php -d error_reporting='' -d extension=ddtrace.so -d extension=ddappsec.so -r 'echo phpversion("ddtrace");' > \
  ./LIBRARY_VERSION

php -d error_reporting='' -d extension=ddtrace.so -d extension=ddappsec.so -r 'echo phpversion("ddappsec");' > \
  ./PHP_APPSEC_VERSION

touch LIBDDWAF_VERSION

appsec_version=$(<./PHP_APPSEC_VERSION)
rule_file="/opt/datadog/dd-library/appsec-${appsec_version}/etc/dd-appsec/recommended.json"
jq -r '.metadata.rules_version // "1.2.5"' "${rule_file}" > APPSEC_EVENT_RULES_VERSION