#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}
architecture="$(uname -m)"

mkdir -p "appsec_${architecture}"

echo "Build nts extension"
export PHP_SDK_VERSION="${PHP_VERSION}"
mkdir -p appsec/build ; cd appsec/build
cmake .. -DCMAKE_BUILD_TYPE=RelWithDebInfo \
  -DCMAKE_C_FLAGS=-Wno-static-in-inline \
  -DDD_APPSEC_TESTING=OFF \
  -DDD_APPSEC_EXTENSION_STATIC_LIBSTDCXX=ON
make -j "${MAKE_JOBS}"
cp -v ddappsec.so "../../appsec_${architecture}/ddappsec-${ABI_NO}.so"
cd "../../"

echo "Build zts extension"
export PHP_SDK_VERSION="${PHP_VERSION}-release-zts"
mkdir -p appsec/build-zts ; cd appsec/build-zts
cmake .. -DCMAKE_BUILD_TYPE=RelWithDebInfo \
  -DCMAKE_C_FLAGS=-Wno-static-in-inline \
  -DDD_APPSEC_TESTING=OFF \
  -DDD_APPSEC_EXTENSION_STATIC_LIBSTDCXX=ON
make -j "${MAKE_JOBS}"
cp -v ddappsec.so "../../appsec_${architecture}/ddappsec-${ABI_NO}-zts.so"
cd "../../"

echo "Compress debug info"
cd "appsec_${architecture}"
for file in ./*.so; do
    if readelf --version-info "$file" | grep GLIBC_ >/dev/null; then
        echo "$file is not portable: found a GLIBC symbol version" >&2
        exit 1
    fi
    objcopy --compress-debug-sections "$file"
done
