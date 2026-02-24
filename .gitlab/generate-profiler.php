<?php

include "generate-common.php";

?>
stages:
  - test

.all_profiler_targets: &all_profiler_targets
<?php
foreach ($profiler_minor_major_targets as $version) {
    echo "  - \"{$version}\"\n";
}
?>

"profiling tests":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:${IMAGE_PREFIX}${PHP_MAJOR_MINOR}${IMAGE_SUFFIX}
  # Setting the *_REQUEST and *_LIMIT variables to be the same, and setting
  # them for both the build and helper allows using Guaranteed QoS instead of
  # Burstable. This means nproc and similar tools will work as expected.
  variables:
    KUBERNETES_CPU_REQUEST: 3
    KUBERNETES_CPU_LIMIT: 3
    KUBERNETES_MEMORY_REQUEST: 6Gi
    KUBERNETES_MEMORY_LIMIT: 6Gi
    KUBERNETES_HELPER_CPU_REQUEST: 1
    KUBERNETES_HELPER_CPU_LIMIT: 1
    KUBERNETES_HELPER_MEMORY_REQUEST: 2Gi
    KUBERNETES_HELPER_MEMORY_LIMIT: 2Gi
    CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
    libdir: /tmp/datadog-profiling
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_profiler_targets
        ARCH: *arch_targets
        IMAGE_PREFIX: php-compile-extension-alpine-
        IMAGE_SUFFIX: [""]
      - PHP_MAJOR_MINOR: *all_profiler_targets
        ARCH: *arch_targets
        IMAGE_PREFIX: php-
        IMAGE_SUFFIX: _centos-7
  script:
    - if [ -d '/opt/rh/devtoolset-7' ]; then set +eo pipefail; source scl_source enable devtoolset-7; set -eo pipefail; fi
    - if [ -f /sbin/apk ] && [ $(uname -m) = "aarch64" ]; then ln -sf ../lib/llvm17/bin/clang /usr/bin/clang; fi

    - cd profiling
    - 'echo "nproc: $(nproc)"'
    - 'echo "KUBERNETES_CPU_REQUEST: ${KUBERNETES_CPU_REQUEST:-<unset>}"'
    - export TEST_PHP_EXECUTABLE=$(which php)
    - run_tests_php=$(find $(php-config --prefix) -name run-tests.php) # don't anticipate there being more than one
    - cp -v "${run_tests_php}" tests
    - unset DD_SERVICE; unset DD_ENV
    - mkdir -p "${CI_PROJECT_DIR}/artifacts/profiler-tests"

    - '# NTS'
    - command -v switch-php && switch-php "${PHP_MAJOR_MINOR}"
    - cargo build --profile profiler-release --all-features
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/nts-results.xml" php profiling/tests/run-tests.php -d "extension=/mnt/ramdisk/cargo/profiler-release/libdatadog_php_profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")

    - touch build.rs #make sure `build.rs` gets executed after `switch-php` call

    - '# ZTS'
    - command -v switch-php && switch-php "${PHP_MAJOR_MINOR}-zts"
    - cargo build --profile profiler-release --all-features
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/zts-results.xml" php profiling/tests/run-tests.php -d "extension=/mnt/ramdisk/cargo/profiler-release/libdatadog_php_profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")
  after_script:
    - |
      if [ "${IMAGE_SUFFIX}" != "_centos-7" ]; then
        .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:profiling"
      else
        echo "Skipping JUnit upload on CentOS 7 (old glibc/OpenSSL incompatible with datadog-ci)"
      fi
  artifacts:
    reports:
      junit: "artifacts/profiler-tests/*.xml"
    paths:
      - "artifacts/"
    when: "always"

"clippy NTS":
  stage: test
  tags: [ "arch:amd64" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-6
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    # CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
    libdir: /tmp/datadog-profiling
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_profiler_targets
  script:
    - switch-php nts # not compatible with debug
    - cd profiling
    - sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml
    - cargo clippy --all-targets --all-features -- -D warnings -Aunknown-lints

"Cargo test":
  stage: test
  tags: [ "arch:amd64" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.5_bookworm-5
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    # CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
    libdir: /tmp/datadog-profiling
  script:
    - switch-php nts # not compatible with debug
    - cd profiling
    - cargo test --all-features
