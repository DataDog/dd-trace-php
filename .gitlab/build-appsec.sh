#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

shopt -s expand_aliases

if [ -d '/opt/rh/devtoolset-7' ] ; then
    set +eo pipefail
    source scl_source enable devtoolset-7
    set -eo pipefail
fi
set -u

echo 'export PHP_API=$(php -i | grep "PHP Extension => " | sed "s/PHP Extension => //g")' >> "$BASH_ENV"
source "${BASH_ENV}"

mkdir -p appsec_$(uname -m)
suffix="${1:-}"

echo "Build nts extension"
switch-php "${PHP_VERSION}"
mkdir -p appsec/build ; cd appsec/build
cmake .. -DCMAKE_BUILD_TYPE=RelWithDebInfo -DDD_APPSEC_BUILD_HELPER=OFF  -DDD_APPSEC_TESTING=OFF ; make -j $MAKE_JOBS
cp -v ddappsec.so "../../appsec_$(uname -m)/ddappsec-$PHP_API${suffix}.so"

echo "Build zts extension"
switch-php "${PHP_VERSION}-zts"
mkdir -p appsec/build-zts ; cd appsec/build-zts
cmake .. -DCMAKE_BUILD_TYPE=RelWithDebInfo -DDD_APPSEC_BUILD_HELPER=OFF  -DDD_APPSEC_TESTING=OFF ; make -j $MAKE_JOBS
cp -v ddappsec.so "../../appsec_$(uname -m)/ddappsec-$PHP_API${suffix}-zts.so"

echo "Compress debug info"
cd appsec_$(uname -m)
for FILE in $(find . -name "*.so"); do
    objcopy --compress-debug-sections $FILE
done
