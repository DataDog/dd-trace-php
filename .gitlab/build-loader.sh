#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

cd loader
export PHP_SDK_VERSION=8.3
phpize
./configure
make clean
make -j "${MAKE_JOBS}" all ECHO_ARG="-e" CFLAGS="-std=gnu11 -O2 -g -Wall -Wextra -Werror -DPHP_DD_LIBRARY_LOADER_VERSION='\"$(cat ../VERSION)\"'"
# The reaper is copied out of the loader. Reject accidental references to its
# code/data, including compiler instrumentation, before packaging the library.
REAPER_RELOCATIONS="$(LC_ALL=C objdump -r -j ddloader_reaper_code .libs/telemetry_reaper.o)"
if [[ "${REAPER_RELOCATIONS}" == *"RELOCATION RECORDS"* ]]; then
  printf 'Telemetry reaper must not contain relocations:\n%s\n' "${REAPER_RELOCATIONS}"
  exit 1
fi

if readelf --version-info modules/dd_library_loader.so | grep GLIBC_ >/dev/null; then
  echo "dd_library_loader.so is not portable: found a GLIBC symbol version" >&2
  exit 1
fi
cp modules/dd_library_loader.so "../dd_library_loader-$(uname -m).so"
