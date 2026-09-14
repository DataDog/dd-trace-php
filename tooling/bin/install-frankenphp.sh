#!/bin/bash

set -eux

FRANKENPHP_VERSION=${FRANKENPHP_VERSION:-v1.12.7}
frankenphpTarGzUrl=https://github.com/dunglas/frankenphp/archive/refs/tags/${FRANKENPHP_VERSION}.tar.gz
FRANKENPHP_SRC_DIR=/usr/local/src/frankenphp

rm -rf $FRANKENPHP_SRC_DIR
mkdir -p $FRANKENPHP_SRC_DIR

curl -Lo /tmp/frankenphp.tar.gz ${frankenphpTarGzUrl}
tar xf /tmp/frankenphp.tar.gz -C ${FRANKENPHP_SRC_DIR} --strip-components=1
rm -f /tmp/frankenphp.tar.gz

cd ${FRANKENPHP_SRC_DIR}
cd caddy/frankenphp

# The CI images are clang-built and clang links the ASAN runtime statically by default, so `ldd`
# alone never sees it -- check for the runtime's init symbol as well, or ASAN silently stays off.
php_bin=$(readlink -f "$(command -v php)")
if ldd "$php_bin" 2>/dev/null | grep -qE 'libasan|libclang_rt\.asan' || nm -a "$php_bin" 2>/dev/null | grep -q __asan_init; then
  # -shared-libasan is required, not optional: libphp.so links the shared ASAN runtime, while clang
  # links the static one into executables by default, and mixing the two makes ASAN abort at startup
  # with "Your application is linked against incompatible ASan runtimes".
  ASAN="-fsanitize=address -shared-libasan"
  # That runtime lives in clang's resource dir, which is not on the default loader path.
  asan_rt_dir=$("${CC:-cc}" -print-runtime-dir 2>/dev/null || true)
  ASAN_LD=${asan_rt_dir:+-Wl,-rpath,${asan_rt_dir}}
else
  ASAN=""
  ASAN_LD=""
fi

# CGO_ENABLED=1 is not redundant: the CI images bake CGO_ENABLED=0 into the Go env, which drops every cgo file...
# -tags nowatcher: the file watcher pulls in a cgo module whose C headers are not vendored in the
# release tarball, and we do not need worker file watching in tests.
CGO_ENABLED=1 CC="${CC:-cc}" CGO_CFLAGS="$(php-config --includes) $ASAN" CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs) $ASAN $ASAN_LD" go build -tags nowatcher

mv frankenphp $(readlink /usr/local/bin/frankenphp)
