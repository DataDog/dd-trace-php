phpBuild=$1

switch-php ${phpBuild}
toolchain=""
if [ "${phpBuild}" = "debug-zts-asan" ]; then
  toolchain="-DCMAKE_TOOLCHAIN_FILE=cmake/asan.cmake"
fi
mkdir -p /tmp/build/tea-${phpBuild}
cd /tmp/build/tea-${phpBuild}
CMAKE_PREFIX_PATH=/opt/catch2 \
cmake \
  ${toolchain} \
  -DCMAKE_INSTALL_PREFIX=/opt/tea/${phpBuild} \
  -DCMAKE_BUILD_TYPE=Debug \
  -DBUILD_TEA_TESTING=ON \
  ~/datadog/tea
make -j all
make install
