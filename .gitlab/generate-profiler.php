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
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
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
    - if [ -f /sbin/apk ]; then ln -sf /usr/bin/clang-19 /usr/bin/cc; fi

    - rustc -vV
    - cc -v

    - cd profiling
    - export TEST_PHP_EXECUTABLE=$(which php)
    - run_tests_php=$(find $(php-config --prefix) -name run-tests.php) # don't anticipate there being more than one
    - cp -v "${run_tests_php}" tests
    - unset DD_SERVICE; unset DD_ENV

    - '# NTS'
    - command -v switch-php && switch-php "${PHP_MAJOR_MINOR}"
    - cargo build --release --all-features
    - (cd tests; php run-tests.php -d "extension=/mnt/ramdisk/cargo/release/libdatadog_php_profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "phpt")

    - touch build.rs #make sure `build.rs` gets executed after `switch-php` call

    - '# ZTS'
    - command -v switch-php && switch-php "${PHP_MAJOR_MINOR}-zts"
    - cargo build --release --all-features
    - (cd tests; php run-tests.php -d "extension=/mnt/ramdisk/cargo/release/libdatadog_php_profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "phpt")

"clippy NTS":
  stage: test
  tags: [ "arch:amd64" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
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

