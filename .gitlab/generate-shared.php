stages:
  - test


"C components ASAN":
  tags: [ "arch:amd64" ]
  image: "registry.ddbuild.io/images/mirror/${IMAGE}"
  parallel:
    matrix:
      - IMAGE:
        - "datadog/dd-trace-ci:centos-7"
        - "datadog/dd-trace-ci:php-compile-extension-alpine"
        - "datadog/dd-trace-ci:buster"
  script:
    - if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"; fi
    - if [ -d "/opt/catch2" ]; then export CMAKE_PREFIX_PATH=/opt/catch2; fi
    - mkdir -p tmp/build/datadog_php_components_asan && cd tmp/build/datadog_php_components_asan
    -  cmake $([ -f "/etc/debian_version" ] && echo "-DCMAKE_TOOLCHAIN_FILE=~/datadog/cmake/asan.cmake") -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ~/datadog/components
    - make -j all
    - make test
  after_script:
    - cp tmp/build/datadog_php_components_asan/Testing/Temporary/LastTest.log tmp/artifacts/LastTestUBSan.log
  artifacts:
    paths:
      - tmp/artifacts

"C components UBSAN":
  tags: [ "arch:amd64" ]
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:buster"
  script:
    - if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"; fi
    - mkdir -p tmp/build/datadog_php_components_ubsan && cd tmp/build/datadog_php_components_ubsan
    - CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_TOOLCHAIN_FILE=~/datadog/cmake/ubsan.cmake -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ~/datadog/components
    - make -j all
    - make test ARGS="--output-on-failure --repeat until-fail:10" # channel is non-deterministic, so run tests a few more times. At the moment, Catch2 tests are not automatically adding labels, so run all tests instead of just channel's: https://github.com/catchorg/Catch2/issues/1590
  after_script:
    - cp tmp/build/datadog_php_components_ubsan/Testing/Temporary/LastTest.log tmp/artifacts/LastTestASan.log
  artifacts:
    paths:
      - tmp/artifacts

