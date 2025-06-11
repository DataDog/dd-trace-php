#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

mkdir -p appsec_$(uname -m)

git config --global --add safe.directory $(pwd)/appsec/third_party/libddwaf
mkdir -p appsec/build ; cd appsec/build
cmake .. -DCMAKE_BUILD_TYPE=RelWithDebInfo -DDD_APPSEC_BUILD_EXTENSION=OFF \
      -DDD_APPSEC_ENABLE_PATCHELF_LIBC=ON \
      -DCMAKE_TOOLCHAIN_FILE=/sysroot/$(arch)-none-linux-musl/Toolchain.cmake
make -j $MAKE_JOBS

objcopy --compress-debug-sections libddappsec-helper.so
cp -v libddappsec-helper.so ../../appsec_$(uname -m)/libddappsec-helper.so

make -j $MAKE_JOBS ddappsec_helper_test
./tests/helper/ddappsec_helper_test

cd ../
cp recommended.json ../appsec_$(uname -m)/
