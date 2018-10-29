#!/bin/bash

EXTENSION_BASE_DIR=/opt/datadog-php
EXTENSION_DIR=${EXTENSION_BASE_DIR}/extensions
EXTENSION_CFG_DIR=${EXTENSION_BASE_DIR}/etc
EXTENSION_LOGS_DIR=${EXTENSION_BASE_DIR}/log
INI_FILE_NAME='ddtrace.ini'

PATH="${PATH}:/usr/local/bin"

mkdir -p $EXTENSION_DIR
mkdir -p $EXTENSION_CFG_DIR
mkdir -p $EXTENSION_LOGS_DIR

echo -e '\nLogging php -i to a file\n'
php -i > "$EXTENSION_LOGS_DIR/php-info.log"

exec > >(tee -i "${EXTENSION_LOGS_DIR}/post-install.log")
exec 2>&1

PHP_VERSION=$(php -i | grep 'PHP API' | awk '{print $NF}')
PHP_CFG_DIR=$(php --ini | grep 'Scan for additional .ini files in:' | sed -e 's/Scan for additional .ini files in://g' | head -n 1 | awk '{print $1}')
PHP_THREAD_SAFETY=$(php -i | grep 'Thread Safety' | awk '{print $NF}' | grep -i enabled)

VERSION_SUFFIX=""
if [[ -n $PHP_THREAD_SAFETY ]]; then
    VERSION_SUFFIX="-zts"
fi

EXTENSION_NAME="ddtrace-${PHP_VERSION}${VERSION_SUFFIX}.so"
INI_FILE_PATH="${EXTENSION_CFG_DIR}/$INI_FILE_NAME"

echo -e "Creating ddtrace.ini\n###"
tee $INI_FILE_PATH <<EOF
[datadog]
extension=${EXTENSION_DIR}/${EXTENSION_NAME}
EOF

PHP_DDTRACE_INI="$PHP_CFG_DIR/$INI_FILE_NAME"

echo -e "###\nLinking ddtrace.ini to ${PHP_DDTRACE_INI}\n"
rm "${PHP_DDTRACE_INI}" && true
ln -s "$INI_FILE_PATH" "${PHP_DDTRACE_INI}"

ENABLED_VERSION="$(php -r "echo phpversion('ddtrace');")"

if [[ -n ${ENABLED_VERSION} ]]; then
    echo -e "Extension ${ENABLED_VERSION} enabled successfully\n"
else
    echo -e "Failed enabling ddtrace extension\n"
    exit 1
fi
