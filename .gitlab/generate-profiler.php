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
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:${IMAGE_PREFIX}${PHP_MAJOR_MINOR}${IMAGE_SUFFIX}
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
    - if [ -d '/opt/rh/devtoolset-7' ] && [ "$(uname -m)" = "aarch64" ]; then export BINDGEN_EXTRA_CLANG_ARGS="-I$(clang --print-resource-dir)/include"; fi
    - if [ -f /sbin/apk ] && [ $(uname -m) = "aarch64" ]; then ln -sf ../lib/llvm17/bin/clang /usr/bin/clang; fi
    - export DD_PROFILING_OUTPUT_PPROF=/tmp/

    - cd profiling
    - 'echo "nproc: $(nproc)"'
    - 'echo "KUBERNETES_CPU_REQUEST: ${KUBERNETES_CPU_REQUEST:-<unset>}"'
    - export TEST_PHP_EXECUTABLE=$(which php)
    - run_tests_php=$(find $(php-config --prefix) -name run-tests.php) # don't anticipate there being more than one
    - cp -v "${run_tests_php}" tests
    - unset DD_SERVICE; unset DD_ENV
    - mkdir -p "${CI_PROJECT_DIR}/artifacts/profiler-tests"

    - '# NTS standalone'
    - '# Use if/then instead of `command -v switch-php && switch-php` — the && form exits 1 when switch-php is absent, which FF_ENABLE_BASH_EXIT_CODE_CHECK treats as a job failure'
    - if command -v switch-php > /dev/null 2>&1; then switch-php "${PHP_MAJOR_MINOR}"; fi
    - (cd ..; phpize && DDTRACE_PROFILING_FEATURES="debug_stats,stack_walking_tests,test,tracing,tracing-subscriber,trigger_time_sample" ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling && make -j$(nproc))
    - test -f "${CI_PROJECT_DIR}/modules/datadog-profiling.so" || { echo "ERROR standalone profiler build did not produce modules/datadog-profiling.so"; find "${CI_PROJECT_DIR}/modules" -maxdepth 1 -type f -print; exit 1; }
    - php -d "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" -r 'if (!extension_loaded("datadog-profiling") || ini_get("datadog.profiling.enabled") === false) { exit(1); }'
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/nts-standalone-results.xml" php profiling/tests/run-tests.php -d "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")

    - '# NTS combined (tracer + profiling in one ddtrace.so, as shipped)'
    - if command -v switch-php > /dev/null 2>&1; then switch-php "${PHP_MAJOR_MINOR}"; fi
    - (cd ..; make distclean || true; phpize && DDTRACE_PROFILING_FEATURES="debug_stats,stack_walking_tests,test,tracing,tracing-subscriber,trigger_time_sample" ./configure --enable-ddtrace-tracer --enable-ddtrace-profiling && make -j$(nproc))
    - test -f "${CI_PROJECT_DIR}/modules/ddtrace.so" || { echo "ERROR combined build did not produce modules/ddtrace.so"; find "${CI_PROJECT_DIR}/modules" -maxdepth 1 -type f -print; exit 1; }
    - php -d "extension=${CI_PROJECT_DIR}/modules/ddtrace.so" -r 'if (!extension_loaded("ddtrace") || ini_get("datadog.profiling.enabled") === false) { exit(1); }'
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/nts-combined-results.xml" php profiling/tests/run-tests.php -d "extension=${CI_PROJECT_DIR}/modules/ddtrace.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")

    - '# ZTS standalone'
    - if command -v switch-php > /dev/null 2>&1; then switch-php "${PHP_MAJOR_MINOR}-zts"; fi
    - (cd ..; make distclean || true; phpize && DDTRACE_PROFILING_FEATURES="debug_stats,stack_walking_tests,test,tracing,tracing-subscriber,trigger_time_sample" ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling && make -j$(nproc))
    - test -f "${CI_PROJECT_DIR}/modules/datadog-profiling.so" || { echo "ERROR standalone profiler ZTS build did not produce modules/datadog-profiling.so"; find "${CI_PROJECT_DIR}/modules" -maxdepth 1 -type f -print; exit 1; }
    - php -d "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" -r 'if (!extension_loaded("datadog-profiling") || ini_get("datadog.profiling.enabled") === false) { exit(1); }'
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/zts-standalone-results.xml" php profiling/tests/run-tests.php -d "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")

    - '# ZTS combined (tracer + profiling in one ddtrace.so, as shipped)'
    - if command -v switch-php > /dev/null 2>&1; then switch-php "${PHP_MAJOR_MINOR}-zts"; fi
    - (cd ..; make distclean || true; phpize && DDTRACE_PROFILING_FEATURES="debug_stats,stack_walking_tests,test,tracing,tracing-subscriber,trigger_time_sample" ./configure --enable-ddtrace-tracer --enable-ddtrace-profiling && make -j$(nproc))
    - test -f "${CI_PROJECT_DIR}/modules/ddtrace.so" || { echo "ERROR combined ZTS build did not produce modules/ddtrace.so"; find "${CI_PROJECT_DIR}/modules" -maxdepth 1 -type f -print; exit 1; }
    - php -d "extension=${CI_PROJECT_DIR}/modules/ddtrace.so" -r 'if (!extension_loaded("ddtrace") || ini_get("datadog.profiling.enabled") === false) { exit(1); }'
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/zts-combined-results.xml" php profiling/tests/run-tests.php -d "extension=${CI_PROJECT_DIR}/modules/ddtrace.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")
  after_script:
    - |
      if [ "${IMAGE_SUFFIX}" != "_centos-7" ]; then
        .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:profiling/"
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
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-10
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_CPU_LIMIT: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 3Gi
    KUBERNETES_HELPER_CPU_REQUEST: 1
    KUBERNETES_HELPER_CPU_LIMIT: 1
    KUBERNETES_HELPER_MEMORY_REQUEST: 2Gi
    KUBERNETES_HELPER_MEMORY_LIMIT: 2Gi
    # CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_profiler_targets
  script:
    - switch-php nts # not compatible with debug
    - DDTRACE_PHP_INCLUDES="$(php-config --includes)" cargo clippy --all-targets --no-default-features --features profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample -- -D warnings -Aunknown-lints

"Cargo test":
  stage: test
  tags: [ "arch:amd64" ]
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-8.5_bookworm-10
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_CPU_LIMIT: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 3Gi
    KUBERNETES_HELPER_CPU_REQUEST: 1
    KUBERNETES_HELPER_CPU_LIMIT: 1
    KUBERNETES_HELPER_MEMORY_REQUEST: 2Gi
    KUBERNETES_HELPER_MEMORY_LIMIT: 2Gi
    # CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
  script:
    - switch-php nts
    - DDTRACE_PHP_INCLUDES="$(php-config --includes)" cargo test --no-default-features --features profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample
    - switch-php zts
    - DDTRACE_PHP_INCLUDES="$(php-config --includes)" cargo test --no-default-features --features profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample

"PHP language tests":
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-10
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
    SKIP_ONLINE_TESTS: "1"
    REPORT_EXIT_STATUS: "1"
    TEST_PHP_JUNIT: "${CI_PROJECT_DIR}/artifacts/tests/php-tests.xml"
    DD_PROFILING_OUTPUT_PPROF: /tmp/
    XFAIL_LIST: dockerfiles/ci/xfail_tests/${PHP_MAJOR_MINOR}.list
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_profiler_targets
        ARCH: amd64
        FLAVOUR: [nts, zts]
  script:
    - unset DD_SERVICE; unset DD_ENV
    - command -v switch-php && switch-php "${FLAVOUR}"
    - phpize
    - ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling
    - make -j$(nproc)
    - echo "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" > /opt/php/${FLAVOUR}/conf.d/profiling.ini
    - php -v
    # Fail loudly if the profiler did not load: otherwise the language tests
    # would run profiler-less and pass, giving a false green.
    - php -m | grep -qx 'datadog-profiling' || { echo 'ERROR datadog-profiling extension is not loaded'; exit 1; }
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
