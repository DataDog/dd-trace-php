phpBuild=$1

switch-php ${phpBuild}

source_root=$(pwd)
mkdir -p /tmp/build/tea-${phpBuild}
cd /tmp/build/tea-${phpBuild}

toolchain=""
if [ "${phpBuild}" = "debug-zts-asan" ]; then
  toolchain="-DCMAKE_TOOLCHAIN_FILE=cmake/asan.cmake"
fi
CMAKE_PREFIX_PATH=/opt/catch2 \
cmake \
  ${toolchain} \
  -DCMAKE_INSTALL_PREFIX=/opt/tea/${phpBuild} \
  -DCMAKE_BUILD_TYPE=Debug \
  -DBUILD_TEA_TESTING=ON \
  "$source_root/tea"
make -j all
make install
