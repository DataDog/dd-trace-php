<?php

include "generate-common.php";

?>
stages:
  - test

.profiling_tests:
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:${PROFILER_TEST_IMAGE}
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
    libdir: /tmp/datadog-profiling
  script:
    - export DD_PROFILING_OUTPUT_PPROF=/tmp/
    - cd profiling
    - 'echo "nproc: $(nproc)"'
    - 'echo "KUBERNETES_CPU_REQUEST: ${KUBERNETES_CPU_REQUEST:-<unset>}"'
    - unset DD_SERVICE; unset DD_ENV
    - mkdir -p "${CI_PROJECT_DIR}/artifacts/profiler-tests"
    - '# NTS'
    - if command -v switch-php > /dev/null 2>&1; then switch-php nts; else switch_php nts; fi
    - export TEST_PHP_EXECUTABLE=$(which php)
    - cp -v "$(find "$(php-config --prefix)" -name run-tests.php | head -n 1)" tests
    - php -d "extension=${PROFILER_NTS_EXTENSION}" -r 'exit((int) (!extension_loaded("datadog-profiling") || !function_exists("Datadog\\Profiling\\trigger_time_sample")));'
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/nts-results.xml" php profiling/tests/run-tests.php -d "extension=${PROFILER_NTS_EXTENSION}" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")
    - '# ZTS'
    - if command -v switch-php > /dev/null 2>&1; then switch-php zts; else switch_php zts; fi
    - export TEST_PHP_EXECUTABLE=$(which php)
    - php -d "extension=${PROFILER_ZTS_EXTENSION}" -r 'exit((int) (!extension_loaded("datadog-profiling") || !function_exists("Datadog\\Profiling\\trigger_time_sample")));'
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/zts-results.xml" php profiling/tests/run-tests.php -d "extension=${PROFILER_ZTS_EXTENSION}" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")
  after_script:
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:profiling/"
  artifacts:
    reports:
      junit: "artifacts/profiler-tests/*.xml"
    paths:
      - "artifacts/"
    when: "always"

<?php
foreach ($profiler_minor_major_targets as $major_minor) {
    $abi_no = $php_versions_to_abi[$major_minor];
    foreach ($arch_targets as $arch) {
        $architecture = $arch === "arm64" ? "aarch64" : "x86_64";
        foreach ([
            "alpine" => "php-compile-extension-alpine-{$major_minor}",
            "bookworm" => "php-{$major_minor}_bookworm-11",
        ] as $distribution => $image) {
?>
"profiling tests: [<?= $major_minor ?>, <?= $arch ?>, <?= $distribution ?>]":
  extends: .profiling_tests
  needs:
    - pipeline: "$PARENT_PIPELINE_ID"
      job: "compile portable profiler extension: [<?= $major_minor ?>, <?= $arch ?>]"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "<?= $arch ?>"
    PROFILER_TEST_IMAGE: "<?= $image ?>"
    PROFILER_NTS_EXTENSION: "${CI_PROJECT_DIR}/datadog-profiling-tests/<?= $architecture ?>/lib/php/<?= $abi_no ?>/test/datadog-profiling.so"
    PROFILER_ZTS_EXTENSION: "${CI_PROJECT_DIR}/datadog-profiling-tests/<?= $architecture ?>/lib/php/<?= $abi_no ?>/test/datadog-profiling-zts.so"

<?php
        }
    }
}
?>

.cargo_test:
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-8.5_bookworm-11
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_CPU_LIMIT: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 3Gi
    KUBERNETES_HELPER_CPU_REQUEST: 1
    KUBERNETES_HELPER_CPU_LIMIT: 1
    KUBERNETES_HELPER_MEMORY_REQUEST: 2Gi
    KUBERNETES_HELPER_MEMORY_LIMIT: 2Gi
    libdir: /tmp/datadog-profiling
  script:
    - |
      for flavour in nts zts; do
        switch-php "${flavour}"
        find "profiler-rust-tests/${ARCHITECTURE}/${flavour}" \
          -maxdepth 1 -type f -perm -0100 -print0 | \
          while IFS= read -r -d '' executable; do
            CC=cc "${executable}"
          done
      done

<?php
foreach ($arch_targets as $arch) {
    $architecture = $arch === "arm64" ? "aarch64" : "x86_64";
?>
"Cargo test: [<?= $arch ?>]":
  extends: .cargo_test
  needs:
    - pipeline: "$PARENT_PIPELINE_ID"
      job: "compile portable profiler rust tests: [8.5, <?= $arch ?>]"
      artifacts: true
  variables:
    ARCH: "<?= $arch ?>"
    ARCHITECTURE: "<?= $architecture ?>"

<?php
}
?>

.php_language_tests:
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-11
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_CPU_LIMIT: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 3Gi
    KUBERNETES_HELPER_CPU_REQUEST: 1
    KUBERNETES_HELPER_CPU_LIMIT: 1
    KUBERNETES_HELPER_MEMORY_REQUEST: 2Gi
    KUBERNETES_HELPER_MEMORY_LIMIT: 2Gi
    CARGO_TARGET_DIR: /tmp/cargo
    libdir: /tmp/datadog-profiling
    SKIP_ONLINE_TESTS: "1"
    REPORT_EXIT_STATUS: "1"
    TEST_PHP_JUNIT: "${CI_PROJECT_DIR}/artifacts/tests/php-tests.xml"
    DD_PROFILING_OUTPUT_PPROF: /tmp/
    XFAIL_LIST: dockerfiles/ci/xfail_tests/${PHP_MAJOR_MINOR}.list
  script:
    - unset DD_SERVICE; unset DD_ENV
    - command -v switch-php && switch-php "${FLAVOUR}"
    - mkdir -p modules
    - cp -v "${PROFILER_EXTENSION}" modules/datadog-profiling.so
    - echo "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" > /opt/php/${FLAVOUR}/conf.d/profiling.ini
    - php -v
    # Fail loudly if the profiler did not load: otherwise the language tests
    # would run profiler-less and pass, giving a false green.
    - php -r 'exit((int) !extension_loaded("datadog-profiling"));' || { echo 'ERROR datadog-profiling extension is not loaded'; exit 1; }
    - cat "${XFAIL_LIST}" profiling/tests/php-language-xfail.list > /tmp/profiler-php-language-xfail.list
    - "if php -r 'exit(PHP_VERSION_ID < 80400 ? 0 : 1);'; then cat profiling/tests/php-language-xfail-pre84.list >> /tmp/profiler-php-language-xfail.list; fi"
    - export XFAIL_LIST=/tmp/profiler-php-language-xfail.list
    - ulimit -c unlimited
    - .gitlab/run_php_language_tests.sh
  after_script:
    - |
      output="${CI_PROJECT_DIR}/artifacts/php-language-tests"
      mkdir -p "${output}"

      # Core files are written relative to the crashing process's working
      # directory when core_pattern is not absolute. PHPT processes run from
      # their test directories, so looking only for /usr/local/src/php/core
      # misses crashes such as ext/pcntl/tests/core. The pattern may also add
      # the PID, producing core.<pid>.
      core_pattern=$(cat /proc/sys/kernel/core_pattern 2>&1)
      {
        echo "core_pattern: ${core_pattern}"
        echo "core ulimit: $(ulimit -c)"
      } | tee "${output}/core-diagnostics.txt"

      search_roots=(/usr/local/src/php "${CI_PROJECT_DIR}")
      if [[ "${core_pattern}" == /* ]]; then
        search_roots+=("$(dirname "${core_pattern}")")
      fi

      # Accommodate patterns such as core, core.%p, and core.%e.%p. Filtering
      # with file avoids mistaking source files whose names begin with "core"
      # for process core dumps.
      mapfile -d '' cores < <(
        while IFS= read -r -d '' candidate; do
          if file -b "${candidate}" 2>/dev/null | grep -q 'core file'; then
            printf '%s\0' "${candidate}"
          fi
        done < <(find "${search_roots[@]}" -type f -name 'core*' -print0 2>/dev/null)
      )
      echo "core files found: ${#cores[@]}" | tee -a "${output}/core-diagnostics.txt"

      for i in "${!cores[@]}"; do
        core="${cores[$i]}"
        echo "core ${i}: ${core}" | tee -a "${output}/core-diagnostics.txt"
        gdb --batch \
          -ex "set pagination off" \
          -ex "info threads" \
          -ex "thread apply all bt full" \
          -ex "thread apply all info registers" \
          -ex "info sharedlibrary" \
          /usr/local/bin/php "${core}" > "${output}/gdb-backtrace-${i}.txt" 2>&1 || true
        mv "${core}" "${output}/core-${i}"
      done
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:profiling/"
  artifacts:
    when: on_failure
    paths:
      - artifacts/php-language-tests/

<?php
foreach ($profiler_minor_major_targets as $major_minor) {
    $abi_no = $php_versions_to_abi[$major_minor];
    foreach ($arch_targets as $arch) {
        $architecture = $arch === "arm64" ? "aarch64" : "x86_64";
        foreach (["nts", "zts"] as $flavour) {
            $suffix = $flavour === "zts" ? "-zts" : "";
?>
"PHP language tests: [<?= $major_minor ?>, <?= $arch ?>, <?= $flavour ?>]":
  extends: .php_language_tests
  needs:
    - pipeline: "$PARENT_PIPELINE_ID"
      job: "compile portable profiler extension: [<?= $major_minor ?>, <?= $arch ?>]"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "<?= $arch ?>"
    FLAVOUR: "<?= $flavour ?>"
    PROFILER_EXTENSION: "datadog-profiling/<?= $architecture ?>/lib/php/<?= $abi_no ?>/datadog-profiling<?= $suffix ?>.so"

<?php
        }
    }
}
?>
