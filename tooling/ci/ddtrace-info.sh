#!/bin/bash
set -eu

PKG=$(find /binaries -maxdepth 1 -name 'dd-library-php-*-gnu.tar.gz')
SETUP=/binaries/datadog-setup.php

if [ "$PKG" != "" ] && [ ! -f "$SETUP" ]; then
  echo "local install failed: package located in /binaries but datadog-setup.php not present, please include it."
  exit 1
fi

if [ "$PKG" == "" ]; then
  unset PKG
fi

export PHP_INI_SCAN_DIR="/etc/php"

echo "Installing php package ${PKG-"{default}"} with setup script $SETUP"
php $SETUP --php-bin=all ${PKG+"--file=$PKG"}