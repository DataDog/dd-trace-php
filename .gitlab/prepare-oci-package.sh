#!/usr/bin/env bash
set -euo pipefail

arch=${ARCH:-$(uname -m)}
if [[ "$arch" == "arm64" ]]; then
    arch="aarch64"
elif [[ "$arch" == "amd64" ]]; then
    arch="x86_64"
fi

rm -rf dd-library-php-ssi
tar xvzf ../dd-library-php-ssi-${arch}-linux.tar.gz
mv dd-library-php-ssi sources
