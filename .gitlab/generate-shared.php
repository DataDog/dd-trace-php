<?php

include "generate-common.php";

$switch_php_versions = ["debug", "debug-zts-asan", "nts", "zts"];

?>

stages:
  - build
  - test


"C components ASAN":
  tags: [ "arch:amd64" ]
  stage: test
  image: "registry.ddbuild.io/images/mirror/${IMAGE}"
  needs: []
  parallel:
    matrix:
      - IMAGE:
        - "datadog/dd-trace-ci:centos-7"
        - "datadog/dd-trace-ci:php-compile-extension-alpine"
        - "datadog/dd-trace-ci:buster"
  script:
    - if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"; fi
    - if [ -d "/opt/catch2" ]; then export CMAKE_PREFIX_PATH=/opt/catch2; fi
    - mkdir -p tmp/build_php_components_asan && cd tmp/build_php_components_asan
    - cmake $([ -f "/etc/debian_version" ] && echo "-DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake") -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ../../components
    - make -j all
    - make test
  after_script:
    - mkdir -p tmp/artifacts
    - cp tmp/build_php_components_asan/Testing/Temporary/LastTest.log tmp/artifacts/LastTestUBSan.log
  artifacts:
    paths:
      - tmp/artifacts

"C components UBSAN":
  tags: [ "arch:amd64" ]
  stage: test
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:buster"
  needs: []
  script:
    - if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"; fi
    - mkdir -p tmp/build_php_components_ubsan && cd tmp/build_php_components_ubsan
    - CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_TOOLCHAIN_FILE=../../cmake/ubsan.cmake -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ../../components
    - make -j all
    - make test ARGS="--output-on-failure --repeat until-fail:10" # channel is non-deterministic, so run tests a few more times. At the moment, Catch2 tests are not automatically adding labels, so run all tests instead of just channel's: https://github.com/catchorg/Catch2/issues/1590
  after_script:
    - mkdir -p tmp/artifacts
    - cp tmp/build_php_components_ubsan/Testing/Temporary/LastTest.log tmp/artifacts/LastTestASan.log
  artifacts:
    paths:
      - tmp/artifacts

"Build & Test Tea":
  tags: [ "arch:amd64" ]
  stage: build
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster"
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *no_asan_minor_major_targets
        SWITCH_PHP_VERSION: <?= str_replace("-asan", "", json_encode($switch_php_versions)), "\n" ?>
      - PHP_MAJOR_MINOR: *asan_minor_major_targets
        SWITCH_PHP_VERSION: <?= json_encode($switch_php_versions), "\n" ?>
  script:
    - sh .gitlab/build-tea.sh $SWITCH_PHP_VERSION
    - cd tmp/build-tea-${SWITCH_PHP_VERSION}
    - make test
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
  after_script:
    - mkdir -p tmp/artifacts/
    - cp tmp/build-tea-${SWITCH_PHP_VERSION}/Testing/Temporary/LastTest.log tmp/artifacts/LastTestASan.log
  artifacts:
    paths:
      - tmp/build-tea-*
      - tmp/artifacts

.tea_test:
  tags: [ "arch:amd64" ]
  stage: test
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster"
  after_script:
    - mkdir -p tmp/artifacts
    - cp tmp/build*/Testing/Temporary/LastTest.log tmp/artifacts/LastTest.log
  artifacts:
    paths:
      - tmp/artifacts

<?php
foreach ($all_minor_major_targets as $major_minor):
    foreach ($switch_php_versions as $switch_php_version):
        $toolchain = "";
        if (version_compare($major_minor, "7.4", "<") && $switch_php_version == "debug-zts-asan") $switch_php_version = "debug-zts";
        if ($switch_php_version == "debug-zts-asan") $toolchain="-DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake";
        # PHP itself is only really ubsan compatible since 7.4
        if ($switch_php_version == "debug" && version_compare($switch_php_version, "7.4", ">=")) $toolchain="-DCMAKE_TOOLCHAIN_FILE=../../cmake/ubsan.cmake";
?>
"Zend Abstract Interface Tests: [<?= $major_minor ?>, <?= $switch_php_version ?>]":
  extends: .tea_test
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
  needs:
    - job: "Build & Test Tea"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            SWITCH_PHP_VERSION: "<?= $switch_php_version ?>"
      artifacts: true
  script:
    - switch-php "<?= $switch_php_version ?>"
    - mkdir -p tmp/build_zai && cd tmp/build_zai
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/build-tea-<?= $switch_php_version ?> cmake <?= $toolchain ?> -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DPhpConfig_ROOT=$(php-config --prefix) ../../zend_abstract_interface
    - make -j all
    - make test
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
<?php
    endforeach;
endforeach;
?>

<?php
foreach (["7.4", "8.0"] as $major_minor):
?>
"ZAI Shared Tests: [<?= $major_minor ?>]":
  extends: .tea_test
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-<?= $major_minor ?>-shared-ext"
  needs:
    - job: "Build & Test Tea"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            SWITCH_PHP_VERSION: nts
      artifacts: true
  script:
    - mkdir -p tmp/build_zai && cd tmp/build_zai
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/build-tea-nts cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DRUN_SHARED_EXTS_TESTS=1 -DPhpConfig_ROOT=$(php-config --prefix) ../../zend_abstract_interface
    - make -j all
    - make test
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
<?php
endforeach;
?>

<?php
foreach ($all_minor_major_targets as $major_minor):
    foreach ($switch_php_versions as $switch_php_version):
        $toolchain = "";
        if (version_compare($major_minor, "7.4", "<") && $switch_php_version == "debug-zts-asan") $switch_php_version = "debug-zts";
        if ($switch_php_version == "debug-zts-asan") $toolchain="-DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake";
?>
"Extension Tea Tests: [<?= $major_minor ?>, <?= $switch_php_version ?>]":
  extends: .tea_test
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
  needs:
    - job: "Build & Test Tea"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            SWITCH_PHP_VERSION: "<?= $switch_php_version ?>"
      artifacts: true
  script:
    - switch-php "<?= $switch_php_version ?>"
    - make install # build ddtrace.so
    - mkdir -p tmp/build_ext-tea && cd tmp/build_ext-tea
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/build-tea-<?= $switch_php_version ?> cmake <?= $toolchain ?> -DCMAKE_BUILD_TYPE=Debug -S ../../tests/tea
    - cmake --build . --parallel
    - make test ARGS="--output-on-failure"
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
<?php
    endforeach;
endforeach;
?>
