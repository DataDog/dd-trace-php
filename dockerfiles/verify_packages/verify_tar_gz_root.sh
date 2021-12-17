#!/usr/bin/env sh

set -e

DD_TRACE_ROOT=$(pwd)
sh ${DD_TRACE_ROOT}/dockerfiles/verify_packages/tar_gz/install.sh

tar -xf ${DD_TRACE_ROOT}/build/packages/datadog-php-tracer-*.x86_64.tar.gz -C /

OPT_USER=$(stat -c '%U' /opt)
echo "Owner of /opt: ${OPT_USER}"
if [ "${OPT_USER}" != "root" ]; then
    echo "Wrong user for /opt: ${OPT_USER}"
    exit 1
fi

DD_USER=$(stat -c '%U' /opt/datadog-php)
echo "Owner of /opt/datadog-php: ${DD_USER}"
if [ "${DD_USER}" != "root" ]; then
    echo "Wrong user for /opt/datadog-php: ${DD_USER}"
    exit 1
fi

echo "Permissions are correct"

echo "Installing as per https://docs.datadoghq.com/tracing/faq/php-tracer-manual-installation/#automatic-ini-file-setup"
/opt/datadog-php/bin/post-install.sh

php --ri=ddtrace

echo ".tar.gz archive correctly installed"
