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

.profiler_correctness_targets: &profiler_correctness_targets
<?php
foreach ($profiler_minor_major_targets as $version) {
    if (version_compare($version, "8.0", ">=")) {
        echo "  - \"{$version}\"\n";
    }
}
?>

"prof-correctness":
  stage: test
  tags: [ "arch:amd64" ]
  image: registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-11
  retry: 1
  needs:
    - job: "prof-correctness-analyzer"
      artifacts: true
  variables:
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_CPU_LIMIT: 5
    KUBERNETES_MEMORY_REQUEST: 6Gi
    KUBERNETES_MEMORY_LIMIT: 6Gi
    KUBERNETES_HELPER_CPU_REQUEST: 1
    KUBERNETES_HELPER_CPU_LIMIT: 1
    KUBERNETES_HELPER_MEMORY_REQUEST: 2Gi
    KUBERNETES_HELPER_MEMORY_LIMIT: 2Gi
    PROFILER_SO: "${CI_PROJECT_DIR}/tmp/build_profiler/modules/datadog-profiling.so"
    PROFILER_LOG: "${CI_PROJECT_DIR}/artifacts/prof-correctness/profiler.log"
    PROF_ANALYZE: "${CI_PROJECT_DIR}/tmp/prof-analyze"
    PARALLEL_VERSION: "1.2.7"
    CARGO_HOME: "${CI_PROJECT_DIR}/.cache/prof-correctness-cargo"
  cache:
    key:
      prefix: "prof-correctness-${PHP_MAJOR_MINOR}-${FLAVOUR}"
      files:
        - Cargo.lock
        - rust-toolchain.toml
    paths:
      - .cache/prof-correctness-cargo/bin/
      - .cache/prof-correctness-cargo/registry/index/
      - .cache/prof-correctness-cargo/registry/cache/
      - .cache/prof-correctness-cargo/git/db/
      - tmp/build_profiler/target-profiling/
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *profiler_correctness_targets
        FLAVOUR: [nts, zts]
  before_script:
<?php unset_dd_runner_env_vars(); ?>
  script:
    - switch-php "${FLAVOUR}"
    - |
      if [ "${FLAVOUR}" = "zts" ]; then
        sudo env PHP_INI_SCAN_DIR= MAKEFLAGS="-j$(nproc)" \
          pecl install -f "parallel-${PARALLEL_VERSION}"
        installed_version="$(php -r 'echo phpversion("parallel");')"
        if [ "${installed_version}" != "${PARALLEL_VERSION}" ]; then
          echo "Expected parallel ${PARALLEL_VERSION}, got ${installed_version}"
          exit 1
        fi
      fi
    - mkdir -p "$(dirname "${PROFILER_LOG}")"
    - ': > "${PROFILER_LOG}"'
    - make compile_profiler PROFILER_FEATURES=trigger_time_sample
    - php -v
    - php -d extension="${PROFILER_SO}" --ri datadog-profiling
    - |
      export DD_PROFILING_ENABLED=Off
      export DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=1
      export DD_PROFILING_EXCEPTION_MESSAGE_ENABLED=1
      test_cases=(
        allocation_sampling_distance
        allocations
        allocation_upscaling_mixed_sizes
        time
        strange_frames
        timeline
        exceptions
        io
        socket_io
        io_upscaling
        allocation_time_combined
        generators
      )
      for test_case in "${test_cases[@]}"; do
        output="${CI_PROJECT_DIR}/profiling/tests/correctness/${test_case}/test.pprof"
        mkdir -p "$(dirname "${output}")"
        DD_PROFILING_OUTPUT_PPROF="${output}" \
          php -d extension="${PROFILER_SO}" \
          "profiling/tests/correctness/${test_case}.php" \
          2>> "${PROFILER_LOG}"
        if compgen -G "${output}.*" > /dev/null; then
          echo "Profile output should not exist:"
          ls -l "${output}".*
          exit 1
        fi
      done
      unset DD_PROFILING_ENABLED
    - |
      export DD_PROFILING_LOG_LEVEL=trace
      export DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=1
      export DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE=1
      export DD_PROFILING_EXCEPTION_MESSAGE_ENABLED=1
      test_cases=(
        allocations
        allocation_upscaling_mixed_sizes
        time
        strange_frames
        timeline
        exceptions
        io
        socket_io
        io_upscaling
        allocation_time_combined
        generators
      )
      for test_case in "${test_cases[@]}"; do
        output="${CI_PROJECT_DIR}/profiling/tests/correctness/${test_case}/test.pprof"
        mkdir -p "$(dirname "${output}")"
        DD_PROFILING_OUTPUT_PPROF="${output}" \
          php -d extension="${PROFILER_SO}" \
          "profiling/tests/correctness/${test_case}.php" \
          2>> "${PROFILER_LOG}"
      done

      output="${CI_PROJECT_DIR}/profiling/tests/correctness/allocation_sampling_distance/test.pprof"
      mkdir -p "$(dirname "${output}")"
      DD_PROFILING_OUTPUT_PPROF="${output}" \
        php -d extension="${PROFILER_SO}" \
        -d datadog.profiling.allocation_sampling_distance=1 \
        profiling/tests/correctness/allocation_sampling_distance.php \
        2>> "${PROFILER_LOG}"

      export DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
      output="${CI_PROJECT_DIR}/profiling/tests/correctness/allocations_1byte/test.pprof"
      mkdir -p "$(dirname "${output}")"
      DD_PROFILING_OUTPUT_PPROF="${output}" \
        php -d extension="${PROFILER_SO}" \
        profiling/tests/correctness/allocations.php \
        2>> "${PROFILER_LOG}"

      output="${CI_PROJECT_DIR}/profiling/tests/correctness/allocations_1byte_no_zend_alloc/test.pprof"
      mkdir -p "$(dirname "${output}")"
      DD_PROFILING_OUTPUT_PPROF="${output}" USE_ZEND_ALLOC=0 \
        php -d extension="${PROFILER_SO}" \
        profiling/tests/correctness/allocations.php \
        2>> "${PROFILER_LOG}"
      unset DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE
    - |
      if [ "${FLAVOUR}" = "zts" ]; then
        output="${CI_PROJECT_DIR}/profiling/tests/correctness/exceptions_zts/test.pprof"
        mkdir -p "$(dirname "${output}")"
        DD_PROFILING_OUTPUT_PPROF="${output}" \
          php -d extension="${PROFILER_SO}" \
          profiling/tests/correctness/exceptions_zts.php \
          2>> "${PROFILER_LOG}"
      fi
    - |
      check_correctness() {
        expected="$1"
        profile="${2:-$1}"
        "${PROF_ANALYZE}" \
          -expectedJson "profiling/tests/correctness/${expected}.json" \
          -pprofPath "profiling/tests/correctness/${profile}/"
      }

      check_correctness allocation_sampling_distance
      check_correctness allocations
      check_correctness allocations allocations_1byte
      check_correctness allocations allocations_1byte_no_zend_alloc
      check_correctness time
      check_correctness strange_frames
      check_correctness timeline
      check_correctness allocation_time_combined
      check_correctness generators
      check_correctness io
      check_correctness socket_io
      check_correctness io_upscaling
      if [ "${FLAVOUR}" = "zts" ]; then
        check_correctness exceptions_zts
      fi
      check_correctness exceptions
      check_correctness allocation_upscaling_mixed_sizes
  after_script:
    - |
      mkdir -p "${CI_PROJECT_DIR}/artifacts/prof-correctness"
      if [ -f "${PROFILER_LOG}" ]; then
        if [ "${CI_JOB_STATUS}" = "failed" ]; then
          tail -n 100 "${PROFILER_LOG}"
        fi
        gzip -9 -f "${PROFILER_LOG}"
      fi
  artifacts:
    when: always
    paths:
      - artifacts/prof-correctness/

"prof-correctness-analyzer":
  stage: test
  tags: [ "arch:amd64" ]
  image: registry.ddbuild.io/images/mirror/golang:1.25.13
  retry: 1
  variables:
    PROF_ANALYZE_VERSION: "v1.0.1-0.20260928190009-7b185e995f39"
    GIT_STRATEGY: empty
    GOMODCACHE: "${CI_PROJECT_DIR}/tmp/go/pkg/mod"
    GOCACHE: "${CI_PROJECT_DIR}/tmp/go/build-cache"
  cache:
    key: prof-correctness-go
    paths:
      - tmp/go/
  script:
    - |
      unset GOPRIVATE
      depot_host="depot-read-api-go.us1.ddbuild.io"
      export GOPROXY="https://${depot_host}/magicmirror/magicmirror/@current/"
      export GONOPROXY=none
      export GONOSUMDB="github.com/DataDog,go.ddbuild.io"
      export GOTOOLCHAIN=local
      go env GOPROXY GONOPROXY GONOSUMDB GOSUMDB GOTOOLCHAIN
      go mod download \
        "github.com/DataDog/prof-correctness@${PROF_ANALYZE_VERSION}"
      module_dir="${GOMODCACHE}/github.com/!data!dog/prof-correctness@${PROF_ANALYZE_VERSION}"
      cd "${module_dir}" || exit 1
      go build -o "${CI_PROJECT_DIR}/tmp/prof-analyze" ./cmd/prof-analyze
  artifacts:
    paths:
      - tmp/prof-analyze

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

    - '# NTS'
    - '# Use if/then instead of `command -v switch-php && switch-php` — the && form exits 1 when switch-php is absent, which FF_ENABLE_BASH_EXIT_CODE_CHECK treats as a job failure'
    - if command -v switch-php > /dev/null 2>&1; then switch-php "${PHP_MAJOR_MINOR}"; fi
    - (cd ..; phpize && DDTRACE_PROFILING_FEATURES="debug_stats,stack_walking_tests,test,tracing,tracing-subscriber,trigger_time_sample" ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling && make -j$(nproc))
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/nts-results.xml" php profiling/tests/run-tests.php -d "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")


    - '# ZTS'
    - if command -v switch-php > /dev/null 2>&1; then switch-php "${PHP_MAJOR_MINOR}-zts"; fi
    - touch ../profiling/build.rs # force regeneration after switch-php changes the php-config symlink target
    - (cd ..; make distclean || true; phpize && DDTRACE_PROFILING_FEATURES="debug_stats,stack_walking_tests,test,tracing,tracing-subscriber,trigger_time_sample" ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling && make -j$(nproc))
    - (cd ../; TEST_PHP_JUNIT="${CI_PROJECT_DIR}/artifacts/profiler-tests/zts-results.xml" php profiling/tests/run-tests.php -d "extension=${CI_PROJECT_DIR}/modules/datadog-profiling.so" --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" "profiling/tests/phpt")
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
    # CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
    libdir: /tmp/datadog-profiling
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_profiler_targets
  script:
    - switch-php nts # not compatible with debug
    - cargo clippy --all-targets --no-deps --no-default-features --features profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample -- -D warnings -Aunknown-lints

"Cargo test":
  stage: test
  tags: [ "arch:amd64" ]
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
    # CARGO_TARGET_DIR: /mnt/ramdisk/cargo # ramdisk??
    libdir: /tmp/datadog-profiling
  script:
    - switch-php nts
    - cargo test --no-default-features --features profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample
    - touch profiling/build.rs # make sure the build helper runs after switch-php
    - switch-php zts
    - cargo test --no-default-features --features profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample

"PHP language tests":
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
