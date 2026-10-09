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
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:${IMAGE}"
  needs: []
  parallel:
    matrix:
      - IMAGE:
        - "centos-7"
        - "php-compile-extension-alpine"
        - "bookworm-11"
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
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:bookworm-11"
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

"shm_gen_cache tests":
  # docker-in-docker: the GenMC trials run GenMC in a container.
  tags: [ "docker-in-docker:amd64" ]
  stage: test
  # The base image carries the pinned Rust toolchain (rust-toolchain.toml).
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:bookworm-11"
  needs: []
  interruptible: true
  rules:
    - if: $CI_COMMIT_BRANCH == "master"
      interruptible: false
    - when: on_success
  variables:
    KUBERNETES_CPU_REQUEST: 8
    KUBERNETES_MEMORY_REQUEST: 8Gi
    KUBERNETES_MEMORY_LIMIT: 10Gi
    # nextest runs at most 4 GenMC trials at once (.config/nextest.toml).
    SGC_GENMC_NTHREADS: 2
    # The internal mirror of the GenMC image (ddoghq/images mirror.yaml);
    # the digest stays the one pinned in verification/tests/genmc.rs.
    SGC_GENMC_REPOSITORY: "registry.ddbuild.io/images/mirror/cataphract/genmc"
    NEXTEST_VERSION: "0.9.140"
  before_script:
    # The image runs as an unprivileged user with passwordless sudo.
    - |
      sudo apt-get update
      sudo apt-get install -y --no-install-recommends ca-certificates curl
      sudo install -m 0755 -d /etc/apt/keyrings
      sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list
      sudo apt-get update
      sudo apt-get install -y --no-install-recommends docker-ce-cli
    - curl -LsSf "https://get.nexte.st/${NEXTEST_VERSION}/linux" | sudo tar zxf - -C /usr/local/bin
    - docker version
  script:
    # The no_std library (the GenMC bitcode builds use it). As an rlib only:
    # the crate is also a staticlib, which needs std.
    - cargo rustc -p shm_gen_cache --no-default-features --crate-type rlib
    - cargo rustc -p shm_gen_cache --no-default-features --features std --crate-type rlib
    # One package per run: selected together, Cargo unifies the verification
    # package's `verify` feature into the library's own test build, which then
    # runs with production assertions off and the test hooks called. Both
    # runs write target/nextest/ci/junit.xml, so each report is copied right
    # after its run; the second runs even if the first fails.
    - mkdir -p artifacts
    - status=0
    - cargo nextest run -p shm_gen_cache --profile ci || status=$?
    - cp target/nextest/ci/junit.xml artifacts/shm-gen-cache-results.xml || true
    - cargo nextest run -p shm_gen_cache_verification --profile ci || status=$?
    - cp target/nextest/ci/junit.xml artifacts/shm-gen-cache-verification-results.xml || true
    - exit $status
  after_script:
    - .gitlab/silent-upload-junit-to-datadog.sh "test.source.file:shm_gen_cache"
  artifacts:
    reports:
      junit: "artifacts/*-results.xml"
    paths:
      - artifacts
      - target/tmp/genmc-verification/trials
    when: "always"
    expire_in: 1 week

"shm_gen_cache tests: windows":
  # Native tests only; the GenMC suite runs in "shm_gen_cache tests".
  tags: [ "windows-v2:2019" ]
  stage: test
  needs: []
  interruptible: true
  rules:
    - if: $CI_COMMIT_BRANCH == "master"
      interruptible: false
    - when: on_success
  variables:
    GIT_STRATEGY: none
    CONTAINER_NAME: ${CI_JOB_NAME_SLUG}-${CI_JOB_ID}
    # The PHP images persist the VC build environment, and the vs17 one
    # carries the pinned Rust toolchain.
    IMAGE: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-8.5_windows"
    NEXTEST_VERSION: "0.9.140"
  script: |
<?php windows_git_setup() ?>

    docker run --env GITLAB_CI=$env:GITLAB_CI --env GITHUB_RELEASES_MIRROR=$env:GITHUB_RELEASES_MIRROR --env NEXTEST_VERSION=$env:NEXTEST_VERSION -v ${pwd}:C:\Users\ContainerAdministrator\app -d --name ${CONTAINER_NAME} ${IMAGE} ping -t localhost
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    # ErrorActionPreference=Continue so cargo's stderr is not turned into a
    # terminating NativeCommandError by the 2>&1 capture.
    $ErrorActionPreference = 'Continue'
    docker exec ${CONTAINER_NAME} powershell.exe -File C:\Users\ContainerAdministrator\app\.gitlab\shm-gen-cache-windows-tests.ps1 2>&1 | Tee-Object -FilePath test.log
    $testCode = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    # Only transient network failures get exit 75 for GitLab auto-retry: the
    # script's own (toolchain, cargo-nextest) and cargo's fatal download
    # errors. Not its retry warnings, which a succeeding build also prints.
    if ($testCode -ne 0) { if (Select-String -Path test.log -Pattern 'error: failed to (download|get|load source|fetch)' -Quiet) { Write-Host "Transient network failure; exiting 75 so GitLab auto-retries"; exit 75 } else { exit $testCode } }
  after_script:
    - |
        # .gitlab/silent-upload-junit-to-datadog.sh needs bash and Linux
        # binaries, so the report only goes to GitLab.
        New-Item -ItemType Directory -Force artifacts | Out-Null
        Copy-Item target\nextest\ci\junit.xml artifacts\shm-gen-cache-windows-results.xml -ErrorAction SilentlyContinue
        try { docker stop -t 5 ${CONTAINER_NAME} } catch { }
        try { docker rm -f ${CONTAINER_NAME} } catch { }
        exit 0
  artifacts:
    reports:
      junit: "artifacts/*-results.xml"
    paths:
      - artifacts
    when: "always"
    expire_in: 1 week

"Build & Test Tea":
  tags: [ "arch:amd64" ]
  stage: build
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-11"
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
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-11"
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
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-11"
  script:
    - |
      if ! command -v cc >/dev/null 2>&1 && ! command -v clang >/dev/null 2>&1 && ! command -v gcc >/dev/null 2>&1; then
        sudo apt-get update
        sudo apt-get install -y build-essential
      fi
    - |
      GENERATED_CONFIG_INPUTS="$(bash tooling/generate-supported-configurations.sh --print-input-files | tr '\n' ' ')"
      BASELINE_CONFIG="$(mktemp)"
      trap 'rm -f "$BASELINE_CONFIG"' EXIT
      cp metadata/supported-configurations.json "$BASELINE_CONFIG"

      bash tooling/generate-supported-configurations.sh

      if ! cmp -s "$BASELINE_CONFIG" metadata/supported-configurations.json; then
        echo "ERROR: @metadata/supported-configurations.json got out of sync with implemented configurations. Please run tooling/generate-supported-configurations.sh locally."
        echo "Generator inputs: $GENERATED_CONFIG_INPUTS"
        diff -u "$BASELINE_CONFIG" metadata/supported-configurations.json || true
        exit 1
      fi

"PHP lint":
  tags: [ "arch:amd64" ]
  stage: test
  needs: []
  variables:
    PHP_MAJOR_MINOR: "<?= $all_minor_major_targets[count($all_minor_major_targets) - 1] ?>"
    GIT_SUBMODULE_STRATEGY: none
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-${PHP_MAJOR_MINOR}_bookworm-11"
  script:
    - switch-php nts
    - bash tooling/php-lint/run.sh

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
<?php if ($switch_php_version == "debug-zts-asan"): ?>
    ASAN_OPTIONS: "detect_stack_use_after_return=0"
<?php endif; ?>
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
  image: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:php-<?= $major_minor ?>-shared-ext-11"
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
    - .gitlab/run-with-retryable-download.sh make install # build ddtrace.so
    - mkdir -p tmp/build_ext-tea && cd tmp/build_ext-tea
    - CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/tea/<?= $switch_php_version ?> cmake <?= $toolchain ?> -DCMAKE_BUILD_TYPE=Debug -S ../../tests/tea
    - cmake --build . --parallel
    - make test ARGS="--output-on-failure"
    - grep -e "=== Total [0-9]+ memory leaks detected ===" Testing/Temporary/LastTest.log && exit 1 || true
<?php
    endforeach;
endforeach;
?>
