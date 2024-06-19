#!/usr/bin/env bash
set -euo pipefail

arch=${ARCH:-$(uname -m)}
if [[ "$arch" == "arm64" ]]; then
    arch="aarch64"
elif [[ "$arch" == "amd64" ]]; then
    arch="x86_64"
fi

# FIXME: handle Alpine/musl in the same package?

rm -rf dd-library-php
tar xvzf ../dd-library-php-${arch}-linux-gnu.tar.gz

mkdir -p sources

cp ../dd_library_loader-${arch}-linux-gnu.so sources/dd_library_loader.so
cp -R dd-library-php/trace sources/
cp dd-library-php/VERSION sources/version

echo 'zend_extension=${DD_LOADER_PACKAGE_PATH}/dd_library_loader.so' > sources/dd_library_loader.ini
