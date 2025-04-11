#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

mkdir -p extensions_$(uname -m)

# Build extension basic .so
switch-php "debug-zts-asan"
make RUST_DEBUG_BUILD=1
cp -v "tmp/build_extension/modules/ddtrace.so" "extensions_$(uname -m)/ddtrace-${ABI_NO}-debug-zts.so"

# Compress debug info
cd extensions_$(uname -m)
for FILE in $(find . -name "*.so"); do
    objcopy --compress-debug-sections $FILE
done
