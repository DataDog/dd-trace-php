#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

shopt -s expand_aliases
echo 'export PHP_API=$(php -i | grep "PHP Extension => " | sed "s/PHP Extension => //g")' >> "$BASH_ENV"
source "${BASH_ENV}"


if [[ "${HOST_OS}" == "linux-musl" ]]; then
  apk add --no-cache \
    autoconf \
    coreutils \
    g++ \
    gcc \
    make
fi

cd loader
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

cp modules/dd_library_loader.so "../dd_library_loader-$(uname -m)-${HOST_OS}.so"
