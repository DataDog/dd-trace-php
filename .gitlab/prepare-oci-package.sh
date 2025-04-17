#!/usr/bin/env bash
set -euo pipefail

if [ "$OS" != "linux" ]; then
  echo "Only linux packages are supported. Exiting"
  exit 0
fi

arch=${ARCH:-$(uname -m)}
if [[ "$arch" == "arm64" ]]; then
    arch="aarch64"
elif [[ "$arch" == "amd64" ]]; then
    arch="x86_64"
fi

rm -rf dd-library-php-ssi
tar xvzf ../dd-library-php-ssi-${arch}-linux.tar.gz

# Remove all debug files
find dd-library-php-ssi -name "*.debug" -delete

mv dd-library-php-ssi sources
