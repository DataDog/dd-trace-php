<?php
$all_minor_major_targets = [
    "7.0",
    "7.1",
    "7.2",
    "7.3",
    "7.4",
    "8.0",
    "8.1",
    "8.2",
    "8.3",
    "8.4",
];

$asan_minor_major_targets = [
  "7.4",
  "8.0",
  "8.1",
  "8.2",
  "8.3",
  "8.4",
];

$arch_targets = ["amd64", "arm64"];
?>

stages:
  - compile
  - test

.all_targets: &all_minor_major_targets
<?php
foreach ($all_minor_major_targets as $version) {
    echo "  - \"{$version}\"\r\n";
}
?>

.asan_targets: &asan_minor_major_targets
<?php
foreach ($asan_minor_major_targets as $version) {
    echo "  - \"{$version}\"\r\n";
}
?>

.arch_targets: &arch_targets
<?php
foreach ($arch_targets as $arch_target) {
    echo "- \"{$arch_target}\"\r\n";
}
?>

"compile extension: debug":
  stage: compile
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_minor_major_targets
        ARCH: *arch_targets
  variables:
    host_os: linux-gnu
    SHARED: "1"
    WITH_ASAN: "0"
    CARGO_HOME: "/rust/cargo/"
    SWITCH_PHP_VERSION: "debug"
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script: |
    ls -l .
    ls -l .gitlab/
    .gitlab/compile_extension.sh
  after_script: |
    export out_dir="modules/${PHP_MAJOR_MINOR}-${SWITCH_PHP_VERSION}-${host_os}-${ARCH}/"
    mkdir -p "${out_dir}"
    mv "tmp/build_extension/modules/ddtrace.so" "${out_dir}"
  cache:
    - key:
        prefix: $CI_JOB_NAME
        files:
          - Cargo.lock
          - Cargo.toml
      paths:
        - /rust/cargo/
  artifacts:
    paths:
      - "VERSION"
      - "modules/"

"compile extension: debug-zts-asan":
  extends: "compile extension: debug"
  variables:
    WITH_ASAN: "1"
    SWITCH_PHP_VERSION: "debug-zts-asan"
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *asan_minor_major_targets
        ARCH: *arch_targets

.base_test:
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  variables:
    host_os: linux-gnu
    COMPOSER_MEMORY_LIMIT: "-1"
    DD_TRACE_ASSUME_COMPILED: "1"
    DDAGENT_HOSTNAME: "127.0.0.1"
    CI_DEBUG_SERVICES: "true"
  before_script:
    - switch-php "${SWITCH_PHP_VERSION}"
    - git config --global --add safe.directory "${CI_PROJECT_DIR}"
    - git config --global --add safe.directory "${CI_PROJECT_DIR}/*"
    - mkdir -p "tmp/build_extension/modules/"
    - mv "modules/${PHP_MAJOR_MINOR}-${SWITCH_PHP_VERSION}-${host_os}-${ARCH}/ddtrace.so" "tmp/build_extension/modules/"
    - for host in ${WAIT_FOR:-}; do wait-for $host --timeout=30; done
  after_script:
    - mv /usr/local/src/php/tests/output/*.log tests/ || true
    - mv /tmp/artifacts/ tests/ || true
    - .gitlab/check_test_agent.sh
    # TODO: Add script
    # - .gitlab/check_for_core_dumps.sh

.debug_test:
  extends: .base_test
  variables:
    SWITCH_PHP_VERSION: debug

<?php
foreach ($all_minor_major_targets as $major_minor) {
?>
"Unit tests: [<?= $major_minor ?>,amd64]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: amd64
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: amd64
  script:
    - make test_unit
<?php
}
?>
