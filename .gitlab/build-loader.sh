#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

cd loader
export PHP_SDK_VERSION=8.3
phpize
./configure
make clean
make -j "${MAKE_JOBS}" all ECHO_ARG="-e" CFLAGS="-std=gnu11 -O2 -g -Wall -Wextra -Werror -DPHP_DD_LIBRARY_LOADER_VERSION='\"$(cat ../VERSION)\"'"
if readelf --version-info modules/dd_library_loader.so | grep GLIBC_ >/dev/null; then
  echo "dd_library_loader.so is not portable: found a GLIBC symbol version" >&2
  exit 1
fi
cp modules/dd_library_loader.so "../dd_library_loader-$(uname -m).so"
