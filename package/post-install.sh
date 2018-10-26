#!/bin/bash

EXTENSION_BASE_DIR=/opt/datadog-php
EXTENSION_DIR=${EXTENSION_BASE_DIR}/extensions
EXTENSION_CFG_DIR=${EXTENSION_BASE_DIR}/etc
EXTENSION_LOGS_DIR=${EXTENSION_BASE_DIR}/log

PHP_VERSION=$(php -i | grep 'PHP API' | awk '{print $NF}')
PHP_CFG_DIR=$(php --ini | grep 'Scan for additional .ini files in:' | sed -e 's/Scan for additional .ini files in://g' | head -n 1 | awk '{print $1}')

PHP_THREAD_SAFETY=$(php -i | grep 'Thread Safety' | awk '{print $NF}' | grep -i enabled)

VERSION_SUFFIX=""
if [[ -n $PHP_THREAD_SAFETY ]]; then
    VERSION_SUFFIX="-zts"
fi

mkdir -p $EXTENSION_DIR
mkdir -p $EXTENSION_CFG_DIR
sudo mkdir -p $EXTENSION_LOGS_DIR

EXTENSION_NAME="ddtrace-${PHP_VERSION}${VERSION_SUFFIX}.so"
INI_FILE_NAME='ddtrace.ini'
INI_FILE_PATH="${EXTENSION_CFG_DIR}/$INI_FILE_NAME"

# TODO: Log php --ini && php -i (we need to be able to debug if the extension was not installed previously)

sudo tee $INI_FILE_PATH <<EOF
[datadog]
extension=${EXTENSION_DIR}/${EXTENSION_NAME}
EOF

sudo ln -s "$INI_FILE_PATH" "$PHP_CFG_DIR/$INI_FILE_NAME"
