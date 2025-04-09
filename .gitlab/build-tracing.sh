#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

shopt -s expand_aliases
echo 'export PHP_API=$(php -i | grep "PHP Extension => " | sed "s/PHP Extension => //g")' >> "$BASH_ENV"
source "${BASH_ENV}"

if [ -d '/opt/rh/devtoolset-7' ] ; then
    set +eo pipefail
    source scl_source enable devtoolset-7
    set -eo pipefail
fi
set -u

suffix="${1:-}"
catch_warnings="${2:-1}"
mkdir -p extensions_$(uname -m) standalone_$(uname -m)

ECHO_ARG="-e"
CFLAGS="-std=gnu11 -O2 -g -Wall -Wextra"
if [ "${suffix}" = "-alpine" ]; then
  CFLAGS="${CFLAGS} -Wno-error=return-local-addr"
fi
if [ "${catch_warnings}" = "1" ]; then
  CFLAGS="${CFLAGS} -Werror"
fi

# Build nts extension
switch-php "${PHP_VERSION}"
make clean && make -j "${MAKE_JOBS}" static
objcopy --compress-debug-sections tmp/build_extension/modules/ddtrace.so "standalone_$(uname -m)/ddtrace-${PHP_API}${suffix}.so"
cp -v tmp/build_extension/modules/ddtrace.a "extensions_$(uname -m)/ddtrace-${PHP_API}${suffix}.a"
if [ "${PHP_VERSION}" = "7.0" ]; then
  cp -v tmp/build_extension/ddtrace.ldflags "ddtrace_$(uname -m)${suffix}.ldflags"
fi

if [ "${suffix}" != "alpine" ]; then
  # Build debug extension
  switch-php "${PHP_VERSION}-debug"
  make clean && make -j "${MAKE_JOBS}" static
  objcopy --compress-debug-sections tmp/build_extension/modules/ddtrace.so "standalone_$(uname -m)/ddtrace-${PHP_API}${suffix}-debug.so"
  cp -v tmp/build_extension/modules/ddtrace.a "extensions_$(uname -m)/ddtrace-${PHP_API}${suffix}-debug.a"
fi

# Build zts extension
switch-php "${PHP_VERSION}-zts"
rm -r tmp/build_extension
make clean && make -j "${MAKE_JOBS}" static
objcopy --compress-debug-sections tmp/build_extension/modules/ddtrace.so "standalone_$(uname -m)/ddtrace-${PHP_API}${suffix}-zts.so"
cp -v tmp/build_extension/modules/ddtrace.a "extensions_$(uname -m)/ddtrace-${PHP_API}${suffix}-zts.a"
