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
        - "datadog/dd-trace-ci:bookworm-6"
  script:
    - if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"; fi
    - if [ -d "/opt/catch2" ]; then export CMAKE_PREFIX_PATH=/opt/catch2; fi
    - mkdir -p tmp/build_php_components_asan && cd tmp/build_php_components_asan
    - cmake $([ -f "/etc/debian_version" ] && echo "-DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake") -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ../../components
    - make -j all
    - mkdir -p "${CI_PROJECT_DIR}/artifacts"
    - make test ARGS="--output-junit ${CI_PROJECT_DIR}/artifacts/components-asan-results.xml --output-on-failure"
  after_script:
    - mkdir -p tmp/artifacts
    - cp tmp/build_php_components_asan/Testing/Temporary/LastTest.log tmp/artifacts/LastTestASan.log
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:components-rs"
  artifacts:
    reports:
      junit: "artifacts/*-results.xml"
    paths:
      - tmp/artifacts
      - artifacts
    when: "always"

"C components UBSAN":
  tags: [ "arch:amd64" ]
  stage: test
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:bookworm-6"
  needs: []
  script:
    - if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"; fi
    - mkdir -p tmp/build_php_components_ubsan && cd tmp/build_php_components_ubsan
    - CMAKE_PREFIX_PATH=/opt/catch2 cmake -DCMAKE_TOOLCHAIN_FILE=../../cmake/ubsan.cmake -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ../../components
    - make -j all
    - mkdir -p "${CI_PROJECT_DIR}/artifacts"
    - make test ARGS="--output-junit ${CI_PROJECT_DIR}/artifacts/components-ubsan-results.xml --output-on-failure --repeat until-fail:10" # channel is non-deterministic, so run tests a few more times. At the moment, Catch2 tests are not automatically adding labels, so run all tests instead of just channel's: https://github.com/catchorg/Catch2/issues/1590
  after_script:
    - mkdir -p tmp/artifacts
    - cp tmp/build_php_components_ubsan/Testing/Temporary/LastTest.log tmp/artifacts/LastTestUBSan.log
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:components-rs"
  artifacts:
    reports:
      junit: "artifacts/*-results.xml"
    paths:
      - tmp/artifacts
      - artifacts
    when: "always"

"Build & Test Tea":
  tags: [ "arch:amd64" ]
  stage: build
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-6"
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *no_asan_minor_major_targets
        SWITCH_PHP_VERSION: <?= str_replace("-asan", "", json_encode($switch_php_versions)), "\n" ?>
      - PHP_MAJOR_MINOR: *asan_minor_major_targets
        SWITCH_PHP_VERSION: <?= json_encode($switch_php_versions), "\n" ?>
  script:
    - sh .gitlab/build-tea.sh $SWITCH_PHP_VERSION
    - cd tmp/build-tea-${SWITCH_PHP_VERSION}
    - mkdir -p "${CI_PROJECT_DIR}/artifacts"
    - make test ARGS="--output-junit ${CI_PROJECT_DIR}/artifacts/tea-${SWITCH_PHP_VERSION}-results.xml --output-on-failure"
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
  after_script:
    - mkdir -p tmp/artifacts/
    - cp tmp/build-tea-${SWITCH_PHP_VERSION}/Testing/Temporary/LastTest.log tmp/artifacts/LastTest.log
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:zend_abstract_interface"
  artifacts:
    reports:
      junit: "artifacts/*-results.xml"
    paths:
      - tmp/tea
      - tmp/artifacts
      - artifacts
    when: "always"

.tea_test:
  tags: [ "arch:amd64" ]
  stage: test
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-6"
  interruptible: true
  rules:
    - if: $CI_COMMIT_BRANCH == "master"
      interruptible: false
    - when: on_success
  after_script:
    - mkdir -p tmp/artifacts
    - cp tmp/build*/Testing/Temporary/LastTest.log tmp/artifacts/LastTest.log
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:zend_abstract_interface"
  artifacts:
    reports:
      junit: "artifacts/*-results.xml"
    paths:
      - tmp/artifacts
      - artifacts
    when: "always"

"Configuration Consistency":
  tags: [ "arch:amd64" ]
  stage: test
  needs: []
  variables:
    PHP_MAJOR_MINOR: "<?= $all_minor_major_targets[count($all_minor_major_targets) - 1] ?>"
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-6"
  script:
    - |
      if ! command -v cc >/dev/null 2>&1 && ! command -v clang >/dev/null 2>&1 && ! command -v gcc >/dev/null 2>&1; then
        sudo apt-get update
        sudo apt-get install -y build-essential
      fi
    - GENERATED_CONFIG_INPUTS="$(bash tooling/generate-supported-configurations.sh --print-input-files | tr '\n' ' ')"
    - bash tooling/generate-supported-configurations.sh
    - |
      if ! git -C "$CI_PROJECT_DIR" diff --exit-code -- metadata/supported-configurations.json; then
        echo "ERROR: @metadata/supported-configurations.json got out of sync with implemented configurations. Please run tooling/generate-supported-configurations.sh locally."
        git -C "$CI_PROJECT_DIR" --no-pager diff -- metadata/supported-configurations.json $GENERATED_CONFIG_INPUTS
        exit 1
      fi

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
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/tea/<?= $switch_php_version ?> cmake <?= $toolchain ?> -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DPhpConfig_ROOT=$(php-config --prefix) ../../zend_abstract_interface
    - make -j all
    - mkdir -p "${CI_PROJECT_DIR}/artifacts"
    - make test ARGS="--output-junit ${CI_PROJECT_DIR}/artifacts/zai-<?= $major_minor ?>-<?= $switch_php_version ?>-results.xml --output-on-failure"
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
    - switch-php nts
<?php if (version_compare($major_minor, "7.4", "<=")): ?>
    - echo "extension=json.so" | sudo tee $(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}')/json.ini
<?php endif; ?>
    - echo "extension=curl.so" | sudo tee $(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}')/curl.ini
    - mkdir -p tmp/build_zai && cd tmp/build_zai
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/tea/nts cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON -DRUN_SHARED_EXTS_TESTS=1 -DPhpConfig_ROOT=$(php-config --prefix) ../../zend_abstract_interface
    - make -j all
    - TEA_INI_IGNORE=0 make test
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
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/tea/<?= $switch_php_version ?> cmake <?= $toolchain ?> -DCMAKE_BUILD_TYPE=Debug -S ../../tests/tea
    - cmake --build . --parallel
    - make test ARGS="--output-on-failure"
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
<?php
    endforeach;
endforeach;
?>
