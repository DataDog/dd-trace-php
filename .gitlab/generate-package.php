<?php

include "generate-common.php";

$build_platforms = [
    [
        "triplet" => "x86_64-alpine-linux-musl",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-%s",
        "arch" => "amd64",
        "host_os" => "linux-musl",
        "targets" => [
            ".apk.x86_64"
        ],
    ],
    [
      "triplet" => "aarch64-alpine-linux-musl",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-%s",
        "arch" => "arm64",
        "host_os" => "linux-musl",
        "targets" => [
            ".apk.aarch64"
        ],
    ],
    [
        "triplet" => "x86_64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_centos-7",
        "arch" => "amd64",
        "host_os" => "linux-gnu",
        "targets" => [
            ".rpm.x86_64",
            ".deb.x86_64",
            ".tar.gz.x86_64",
        ],
    ],
    [
        "triplet" => "aarch64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_centos-7",
        "arch" => "arm64",
        "host_os" => "linux-gnu",
        "targets" => [
            ".rpm.arm64",
            ".deb.arm64",
            ".tar.gz.aarch64",
        ],
    ]
];

$asan_build_platforms = [
    [
        "triplet" => "x86_64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_buster",
        "arch" => "amd64",
        "host_os" => "linux-gnu",
    ],
    [
        "triplet" => "aarch64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_buster",
        "arch" => "arm64",
        "host_os" => "linux-gnu",
    ]
];

$windows_build_platforms = [
    [
        "triplet" => "x86_64-pc-windows-msvc",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_windows",
        "arch" => "amd64",
        "host_os" => "windows-msvc",
        "targets" => [
            "dbgsym.tar.gz",
        ],
    ],
];

?>

stages:
  - prepare
  - profiler
  - appsec
  - tracing
  - packaging
  - verify
  - shared-pipeline # OCI packaging

variables:
  CARGO_HOME: "${CI_PROJECT_DIR}/.cache/cargo"

include:
  - remote: https://gitlab-templates.ddbuild.io/libdatadog/include/one-pipeline.yml

# One pipeline job overrides
configure_system_tests:
  variables:
    SYSTEM_TESTS_SCENARIOS_GROUPS: "simple_onboarding,simple_onboarding_profiling,lib-injection,lib-injection-profiling"

requirements_json_test:
  rules:
    - when: on_success
  variables:
    REQUIREMENTS_BLOCK_JSON_PATH: "loader/packaging/block_tests.json"
    REQUIREMENTS_ALLOW_JSON_PATH: "loader/packaging/allow_tests.json"


# dd-trace-php release packaging
"prepare code":
  stage: prepare
  image: registry.ddbuild.io/images/mirror/composer:2
  tags: [ "arch:amd64" ]
  script:
    - ./.gitlab/append-build-id.sh
    # Upgrading composer
    - composer self-update --no-interaction
    # Installing dependencies with composer
    - |
      export COMPOSER_MEMORY_LIMIT=-1 # disable composer memory limit completely
      composer update --no-interaction
    # Compiling dd-tace-php files into single file
    - make generate
    # Showing folder containing generated files
    - ls -al ${CI_PROJECT_DIR:-.}/src/bridge
  artifacts:
    paths:
      - VERSION
      - ./src/bridge/_generated*.php

<?php
foreach ($build_platforms as $platform) {
    $image = sprintf($platform['image_template'], "8.1");
?>
"cache cargo deps: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: prepare
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  variables:
    TRIPLET: "<?= $platform['triplet'] ?>"
    IMAGE: "<?= $image ?>"
    ARCH: "<?= $platform['arch'] ?>"
  script: |
    if [ -e "${CARGO_HOME}/.package-cache" ] ; then
        echo "WARNING: .package-cache was part of the cache and shouldn't be!"
        rm -vf "${CARGO_HOME}/.package-cache"
    fi

    mkdir -p "${CARGO_HOME}"
    cargo fetch -v --target "${TRIPLET}"
    chmod -R 777 "${CARGO_HOME}"

    # ensure the .package-cache isn't there
    rm -vf "${CARGO_HOME}/.package-cache"
  cache:
    - key:
        prefix: cargo-cache-${TRIPLET}
        files:
          - Cargo.lock
      paths:
        - "${CARGO_HOME}"

<?php
}

foreach ($build_platforms as $platform) {
    foreach ($profiler_minor_major_targets as $major_minor) {
        $abi_no = $php_versions_to_abi[$major_minor]
?>
"compile profiler extension: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: profiler
  image: "<?= sprintf($platform['image_template'], $major_minor) ?>"
  tags: [ "arch:$ARCH" ]
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "cache cargo deps: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
  variables:
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
    PHP_VERSION: "<?= $major_minor ?>"
    CARGO_BUILD_JOBS: 12
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script:
    - .gitlab/build-profiler.sh "datadog-profiling/${TRIPLET}/lib/php/${ABI_NO}" "nts"
    - .gitlab/build-profiler.sh "datadog-profiling/${TRIPLET}/lib/php/${ABI_NO}" "zts"
  cache:
    - key:
        prefix: cargo-cache-${TRIPLET}
        files:
          - Cargo.lock
      paths:
        - "${CARGO_HOME}"
      policy: pull  # `Cache Cargo Deps` is used to update/push the cache
  artifacts:
    paths:
      - "datadog-profiling"

<?php
    }
}
?>


<?php
foreach ($build_platforms as $platform) {
    foreach ($php_versions_to_abi as $major_minor => $abi_no) {
        $image = sprintf($platform['image_template'], $major_minor);
        $suffix = ($platform['triplet'] === "x86_64-alpine-linux-musl" || $platform['triplet'] === "aarch64-alpine-linux-musl") ? "-alpine" : "";
?>
"compile appsec extension: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: appsec
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  needs: [ "prepare code" ]
  variables:
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
    PHP_VERSION: "<?= $major_minor ?>"
    MAKE_JOBS: 12
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script:
    # Fix for $BASH_ENV not having a newline at the end of the file
    - echo "" >> "$BASH_ENV"
<?php
if ($suffix == "-alpine") {
?>
    - apk add cmake gcc g++ git python3 autoconf coreutils
<?php
 }
?>
    - .gitlab/build-appsec.sh <?= $suffix ?>

  artifacts:
    paths:
      - "appsec_*"

<?php
    }
}
?>

"compile appsec helper":
  stage: appsec
  image: "registry.ddbuild.io/images/mirror/b1o7r7e0/nginx_musl_toolchain"
  tags: [ "arch:$ARCH" ]
  needs: [ "prepare code" ]
  parallel:
    matrix:
      - ARCH: ["amd64", "arm64" ]
  variables:
    MAKE_JOBS: 12
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script: .gitlab/build-appsec-helper.sh
  artifacts:
    paths:
      - "appsec_*"

"pecl build":
  stage: tracing
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-7.4_buster"
  tags: [ "arch:amd64" ]
  needs: [ "prepare code" ]
  script:
    - make build_pecl_package
    - mkdir -p ./pecl && cp datadog_trace-*.tgz ./pecl
  artifacts:
    paths:
      - pecl

<?php
foreach ($build_platforms as $platform) {
    foreach ($php_versions_to_abi as $major_minor => $abi_no) {
        $image = sprintf($platform['image_template'], $major_minor);
        $suffix = ($platform['triplet'] === "x86_64-alpine-linux-musl" || $platform['triplet'] === "aarch64-alpine-linux-musl") ? "-alpine" : "";
        $catch_warnings = ($major_minor == "7.3" && $suffix != "-alpine") ? "0" : "1";
?>
"compile tracing extension: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: tracing
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  needs: [ "prepare code" ]
  variables:
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
    PHP_VERSION: "<?= $major_minor ?>"
    MAKE_JOBS: 12
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script:
    # Fix for $BASH_ENV not having a newline at the end of the file
    - echo "" >> "$BASH_ENV"
    - ./.gitlab/build-tracing.sh "<?= $suffix ?>" "<?= $catch_warnings ?>"
  artifacts:
    paths:
      - "extensions_*"
      - "standalone_*"
      - "ddtrace_*.ldflags"

<?php
    }
}
?>

<?php
foreach ($build_platforms as $platform) {
    $image = sprintf($platform['image_template'], "8.1");
    $suffix = ($platform['triplet'] === "x86_64-alpine-linux-musl" || $platform['triplet'] === "aarch64-alpine-linux-musl") ? "-alpine" : "";
?>
"compile tracing sidecar: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: tracing
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "cache cargo deps: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
  variables:
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    HOST_OS: "<?= $platform['host_os'] ?>"
    CARGO_BUILD_JOBS: 16
    KUBERNETES_CPU_REQUEST: 16
    KUBERNETES_MEMORY_REQUEST: 5Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script:
    - echo "" >> "$BASH_ENV"
    - ./.gitlab/build-sidecar.sh "<?= $suffix ?>"
  cache:
    - key:
        prefix: cargo-cache-${TRIPLET}
        files:
          - Cargo.lock
      paths:
        - "${CARGO_HOME}"
      policy: pull  # `cache cargo deps` is used to update/push the cache
  artifacts:
    paths:
      - "libddtrace_php_*.*"
<?php
}
?>


<?php
foreach ($build_platforms as $platform) {
    $image = sprintf($platform['image_template'], "8.1");
    $suffix = ($platform['triplet'] === "x86_64-alpine-linux-musl" || $platform['triplet'] === "aarch64-alpine-linux-musl") ? "-alpine" : "";
?>
"link tracing extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: tracing
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  needs:
    - job: "compile tracing sidecar: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
foreach ($php_versions_to_abi as $major_minor => $abi_no) {
?>
    - job: "compile tracing extension: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
}
?>
  variables:
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 8Gi
    KUBERNETES_MEMORY_LIMIT: 16Gi
  script:
    # Fix for $BASH_ENV not having a newline at the end of the file
    - echo "" >> "$BASH_ENV"
    - ./.gitlab/link-tracing-extension.sh "<?= $suffix ?>"
  artifacts:
    paths:
      - "extensions_*"
<?php
}
?>

<?php
foreach ($asan_build_platforms as $platform) {
    foreach ($asan_minor_major_targets as $major_minor) {
        $abi_no = $php_versions_to_abi[$major_minor];
        $image = sprintf($platform['image_template'], $major_minor);
?>
"compile tracing extension asan: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: tracing
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  needs:
    - job: "prepare code"
      artifacts: true
  variables:
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
    PHP_VERSION: "<?= $major_minor ?>"
    MAKE_JOBS: 12
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script: ./.gitlab/build-tracing-asan.sh
  artifacts:
    paths:
      - "extensions_*"
<?php
    }
}
?>

<?php
foreach ($windows_build_platforms as $platform) {
    foreach ($windows_minor_major_targets as $major_minor) {
        $abi_no = $php_versions_to_abi[$major_minor];
        $image = sprintf($platform['image_template'], $major_minor);
?>
"compile extension windows: [<?= $major_minor ?>]":
  stage: tracing
  tags: [ "windows-v2:2019"]
  needs:
    - job: "prepare code"
      artifacts: true
  variables:
    IMAGE: "<?= $image ?>"
    ABI_NO: "<?= $abi_no ?>"
    PHP_VERSION: "<?= $major_minor ?>"
    GIT_CONFIG_COUNT: 1
    GIT_CONFIG_KEY_0: core.longpaths
    GIT_CONFIG_VALUE_0: true
    CONTAINER_NAME: ${CI_JOB_NAME_SLUG}-${CI_JOB_ID}
  script: |
    # Make sure we actually fail if a command fails
    $ErrorActionPreference = 'Stop'
    $PSNativeCommandUseErrorActionPreference = $true

    mkdir extensions_x86_64
    mkdir extensions_x86_64_debugsymbols

    # Start the container
    docker run -v ${pwd}:C:\Users\ContainerAdministrator\app -d --name ${CONTAINER_NAME} ${IMAGE} ping -t localhost

    # Build nts
    docker exec ${CONTAINER_NAME} powershell.exe "cd app; switch-php nts; C:\php\SDK\phpize.bat; .\configure.bat --enable-debug-pack; nmake; move x64\Release\php_ddtrace.dll extensions_x86_64\php_ddtrace-${ABI_NO}.dll; move x64\Release\php_ddtrace.pdb extensions_x86_64_debugsymbols\php_ddtrace-${ABI_NO}.pdb"

    # Reuse libdatadog build
    docker exec ${CONTAINER_NAME} powershell.exe "mkdir app\x64\Release_TS; mv app\x64\Release\target app\x64\Release_TS\target"

    # Build zts
    docker exec ${CONTAINER_NAME} powershell.exe "cd app; switch-php zts; C:\php\SDK\phpize.bat; .\configure.bat --enable-debug-pack; nmake; move x64\Release_TS\php_ddtrace.dll extensions_x86_64\php_ddtrace-${ABI_NO}-zts.dll; move x64\Release_TS\php_ddtrace.pdb extensions_x86_64_debugsymbols\php_ddtrace-${ABI_NO}-zts.pdb"

    # Try to stop the container, don't care if we fail
    try { docker stop -t 5 ${CONTAINER_NAME} } catch { }
  artifacts:
    paths:
      - "extensions_x86_64"
      - "./extensions_x86_64_debugsymbols"
<?php
    }
}
?>

<?php
foreach ($build_platforms as $platform) {
    $image = sprintf($platform['image_template'], "8.3");
?>
"compile loader: [<?= $platform['host_os'] ?>, <?= $platform['arch'] ?>]":
  stage: tracing
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  needs:
    - job: "prepare code"
      artifacts: true
  variables:
    IMAGE: "<?= $image ?>"
    ARCH: "<?= $platform['arch'] ?>"
    HOST_OS: "<?= $platform['host_os'] ?>"
    MAKE_JOBS: 2
  script:
    # Fix for $BASH_ENV not having a newline at the end of the file
    - echo "" >> "$BASH_ENV"
    - ./.gitlab/build-loader.sh
  artifacts:
    paths:
      - "dd_library_loader-*.so"
<?php
}
?>

.package_extension_base:
  stage: packaging
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php_fpm_packaging
  tags: [ "arch:amd64" ]
  artifacts:
    paths:
      - "packages/"

<?php
foreach ($build_platforms as $platform) {
?>
"package extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  extends: .package_extension_base
  variables:
    ARCH: "<?= $platform['arch'] ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
  script:
    - make -j 4 <?= implode(' ', $platform['targets']) ?>

    - ./tooling/bin/generate-final-artifact.sh $(<VERSION) "build/packages" "${CI_PROJECT_DIR}"
    - mv build/packages/ packages/
  needs:
    - job: "prepare code"
      artifacts: true

    # Link tracing extension
    - job: "link tracing extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true

    # Compile tracing sidecar
    - job: "compile tracing sidecar: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true

<?php
    foreach ($php_versions_to_abi as $major_minor => $abi_no) {
?>
    # Compile appsec extension
    - job: "compile appsec extension: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
}
?>

    # Compile appsec helper
    - job: "compile appsec helper"
      parallel:
        matrix:
          - ARCH: "<?= $platform['arch'] ?>"
      artifacts: true

<?php
    foreach ($profiler_minor_major_targets as $major_minor) {
?>
    # Profiler extension
    - job: "compile profiler extension: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
    }
}
?>

"package extension windows":
  extends: .package_extension_base
  variables:
    TRIPLET: "x86_64-pc-windows-msvc"
  script:
    - make -j 4 <?= implode(' ', $windows_build_platforms[0]['targets']), "\n" ?>
    - ./tooling/bin/generate-final-artifact.sh $(<VERSION) "build/packages" "${CI_PROJECT_DIR}"
    - mv build/packages/ packages/
  needs:
    - job: "prepare code"
      artifacts: true
<?php
foreach ($windows_minor_major_targets as $major_minor) {
?>
    - job: "compile extension windows: [<?= $major_minor ?>]"
      artifacts: true
<?php
}
?>

"package extension asan":
  extends: .package_extension_base
  script:
    - ./tooling/bin/generate-final-artifact.sh $(<VERSION) "build/packages" "${CI_PROJECT_DIR}"
    - mv build/packages/ packages/
  needs:
    - job: "prepare code"
      artifacts: true
<?php
foreach ($asan_build_platforms as $platform) {
    foreach ($asan_minor_major_targets as $major_minor) {
        $abi_no = $php_versions_to_abi[$major_minor];
?>
    - job: "compile tracing extension asan: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
    }
}
?>
  variables:
    DDTRACE_MAKE_PACKAGES_ASAN: 1

<?php /*
<?php foreach ($arch_targets as $arch): ?>
"package loader: [<?= $arch ?>]":
  extends: .package_extension_base
  variables:
    ARCHITECTURE: "<?= $arch ?>"
  script:
    - ./tooling/bin/generate-ssi-package.sh $(<VERSION) "build/packages" "${CI_PROJECT_DIR}"
    - mv build/packages/ packages/
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "compile appsec helper"
      parallel:
        matrix:
          - ARCH: "<?= $arch ?>"
      artifacts: true
    - job: "compile loader: [linux-gnu, <?= $arch ?>]":
      artifacts: true
    - job: "compile loader: [linux-musl, <?= $arch ?>]":
      artifacts: true
<?php
    foreach ($build_platforms as $platform):
        if ($platform["arch"] == $arch):
?>
    - job: "compile tracing sidecar: [<?= $arch ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
            foreach ($all_minor_major_targets as $major_minor):
?>
    - job: "compile tracing extension: [<?= $major_minor ?>, <?= $arch ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
    - job: "compile appsec extension: [<?= $major_minor ?>, <?= $arch ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
            endforeach;
?>

<?php
            foreach ($profiler_minor_major_targets as $major_minor):
?>
    - job: "compile profiler extension: [<?= $major_minor ?>, <?= $arch ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
            endforeach;
        endif;
    endforeach;
endforeach;
*/
?>

"datadog-setup.php":
  stage: packaging
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php_fpm_packaging
  tags: [ "arch:amd64" ]
  script:
    - mkdir -p "build/packages"
    - make "build/packages/datadog-setup.php"
    - mv "build/packages/" "packages/"
  needs:
    - job: "prepare code"
      artifacts: true
  artifacts:
    paths:
      - "packages/datadog-setup.php"

"x-profiling phpt tests on Alpine":
  stage: verify
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-$PHP_VERSION"
  tags: [ "arch:amd64" ]
  parallel:
    matrix:
      - PHP_VERSION: <?= json_encode($profiler_minor_major_targets), "\n" ?>
  needs:
    - job: "package extension: [amd64, x86_64-alpine-linux-musl]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  before_script:
    - installable_bundle=$(find packages -maxdepth 1 -name 'dd-library-php-*-x86_64-linux-musl.tar.gz')
    - php datadog-setup.php --file "${installable_bundle}" --php-bin php --enable-profiling
    - phpize # run phpize just to get run-tests.php
  script:
    - php run-tests.php -p $(which php) -d datadog.remote_config_enabled=false --show-diff -g "FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP" tests/ext/profiling

.randomized_tests:
  stage: verify
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:24.0.4-gbi-focal # TODO: use a proper docker image with make, php and git pre-installed
  variables:
    KUBERNETES_CPU_REQUEST: 7
    KUBERNETES_MEMORY_REQUEST: 30Gi
    KUBERNETES_MEMORY_LIMIT: 40Gi
    RUST_BACKTRACE: 1
  before_script:
    - apt install -y php git make curl
    - curl -L --fail https://github.com/docker/compose/releases/download/v2.36.0/docker-compose-linux-x86_64 -o /usr/local/bin/docker-compose
    - chmod +x /usr/local/bin/docker-compose
    - mv packages/* .
    - make -C tests/randomized library.local # Copy tracer package
    - make -C tests/randomized generate PLATFORMS=$RANDOMIZED_RESTRICT_PLATFORMS NUMBER_OF_SCENARIOS=4
  script:
    - make -C tests/randomized test CONCURRENT_JOBS=2 DURATION=1m30s # Execute
  after_script:
  # - sudo chown -R circleci:circleci tests/randomized/.tmp.scenarios/.results
    - make -C tests/randomized analyze
  artifacts:
    paths:
      - tests/randomized/.tmp.scenarios/.results


<?php foreach (range(1, 5) as $i): ?>
"randomized tests: [amd64, no-asan, <?= $i ?>]":
  extends: .randomized_tests
  tags: [ "docker-in-docker:amd64" ]
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true

<?php endforeach; ?>

<?php foreach (range(1, 5) as $i): ?>
"randomized tests: [amd64, asan, <?= $i ?>]":
  extends: .randomized_tests
  tags: [ "docker-in-docker:amd64" ]
  needs:
    - job: "package extension asan"
      artifacts: true

<?php endforeach; ?>

<?php foreach (range(1, 5) as $i): ?>
"randomized tests: [arm64, no-asan, <?= $i ?>]":
  extends: .randomized_tests
  tags: [ "runner:docker-arm" ]
  needs:
    - job: "package extension: [arm64, aarch64-unknown-linux-gnu]"
      artifacts: true

<?php endforeach; ?>

<?php foreach (range(1, 5) as $i): ?>
"randomized tests: [arm64, asan, <?= $i ?>]":
  extends: .randomized_tests
  tags: [ "runner:docker-arm" ]
  needs:
    - job: "package extension asan"
      artifacts: true

<?php endforeach; ?>

"installer tests":
  stage: verify
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:24.0.4-gbi-focal
  tags: [ "docker-in-docker:amd64" ]
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
    - job: "package extension: [arm64, aarch64-unknown-linux-gnu]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 2Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    RUST_BACKTRACE: 1
  before_script:
    - apt install -y make
    - mkdir build
    - mv packages build
  script:
    - make -C dockerfiles/verify_packages test_installer

"test early PHP 8.1":
  stage: verify
  image: registry.ddbuild.io/images/mirror/ubuntu:jammy
  tags: [ "arch:amd64" ]
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 2Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    RUST_BACKTRACE: 1
  before_script:
<?php unset_dd_runner_env_vars() ?>
    - apt-get update -y
    - DEBIAN_FRONTEND=noninteractive apt-get install -y php8.1 php8.1-dom php-pear
    - rm /etc/php/8.1/cli/conf.d/10-opcache.ini
  script:
    - php datadog-setup.php --php-bin all --file $(ls packages/dd-library-php-*-x86_64-linux-gnu.tar.gz)
    - sed -i 's/datadog.trace.sources_path/\;datadog.trace.sources_path/' /etc/php/8.1/cli/conf.d/98-ddtrace.ini
    - DD_TRACE_GIT_METADATA_ENABLED=0 pecl run-tests --showdiff --ini=" -d datadog.trace.cli_enabled=1" $(find tests/ext -type d)

"framework test":
  stage: verify
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:24.0.4-gbi-focal
  tags: [ "docker-in-docker:amd64" ]
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 2Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
  parallel:
    matrix:
      - TESTSUITE:
        - flow
        - flow_no_ddtrace
        - mongodb-driver
        - mongodb-driver_no_ddtrace
        - phpredis3
        - phpredis3_no_ddtrace
        - phpredis4
        - phpredis4_no_ddtrace
        - phpredis5
        - phpredis5_no_ddtrace
        - wordpress
        - wordpress_no_ddtrace

        # The dd-trace-ci:php-framework-laravel docker image needs to be modified to handle the laravel queue integration
        # - laravel_no_ddtrace
        # - laravel
        # Symfony path needs to be updated as symfony/contracts 2.0 has been released and it changes
        # - symfony_no_ddtrace
        # - symfony
  before_script:
    - apt install -y make curl
    - curl -L --fail https://github.com/docker/compose/releases/download/v2.36.0/docker-compose-linux-x86_64 -o /usr/local/bin/docker-compose
    - chmod +x /usr/local/bin/docker-compose
    - mkdir build
    - mv packages build
    - docker-compose --version
  script:
    - make -f dockerfiles/frameworks/Makefile $TESTSUITE
  artifacts:
    paths:
      - tests/randomized/.tmp.scenarios/.results

.verify_job:
  stage: verify
  image: "registry.ddbuild.io/images/mirror/$IMAGE"
  tags: [ "arch:amd64" ]
  services:
    - !reference [.services, request-replayer]
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 2Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    DD_AGENT_HOST: request-replayer
    DD_TRACE_AGENT_PORT: 80
    DD_TRACE_AGENT_FLUSH_INTERVAL: 1000
  script:
    - ./dockerfiles/verify_packages/verify.sh


"verify alpine":
  extends: .verify_job
  variables:
    VERIFY_APACHE: no
  parallel:
    matrix:
      - INSTALL_PACKAGES: php7 php7-fpm php7-json
        IMAGE:
          - alpine:3.8
          - alpine:3.9
          - alpine:3.10
          - alpine:3.11
          - alpine:3.12
          - alpine:3.15
        INSTALL_TYPE: &verify_install_types
        - php_installer
        - native_package
      - INSTALL_PACKAGES: php php-fpm php-json
        IMAGE:
          - alpine:3.15
          - alpine:3.16
          - alpine:3.17
          - alpine:3.20
          - alpine:latest
        INSTALL_TYPE: *verify_install_types
      - IMAGE: <?= json_encode(array_map(function ($v) { return "php:$v-fpm-alpine"; }, $all_minor_major_targets)), "\n" ?>
        INSTALL_TYPE: *verify_install_types
  needs:
    - job: "package extension: [amd64, x86_64-alpine-linux-musl]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  before_script: &verify_alpine_before_script
    - mkdir build
    - mv packages build
    - apk add --no-cache ca-certificates # see https://support.circleci.com/hc/en-us/articles/360016505753-Resolve-Certificate-Signed-By-Unknown-Authority-error-in-Alpine-images?flash_digest=39b76521a337cecacac0cc10cb28f3747bb5fc6a
    - apk add curl ${INSTALL_PACKAGES:-}

"verify centos":
  extends: .verify_job
  variables:
    IMAGE: centos:7
  parallel:
    matrix:
      - PHP_MINOR_MAJOR:
          - 70
          - 71
          - 72
          - 73
          - 74
          - 80
          - 81
          - 82
          - 83
        INSTALL_TYPE: *verify_install_types
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  before_script:
    - mkdir build
    - mv packages build
    - '# Fix yum config, as centos 7 is EOL and mirrorlist.centos.org does not resolve anymore - https://serverfault.com/a/1161847'
    - sed -i s/mirror.centos.org/vault.centos.org/g /etc/yum.repos.d/*.repo
    - sed -i s/^#.*baseurl=http/baseurl=http/g /etc/yum.repos.d/*.repo
    - sed -i s/^mirrorlist=http/#mirrorlist=http/g /etc/yum.repos.d/*.repo
    - yum update -y

"verify debian":
  extends: .verify_job
  variables:
    INSTALL_MODE: sury
  parallel:
    matrix:
      - PHP_VERSION: <?= json_encode($all_minor_major_targets), "\n" ?>
        INSTALL_TYPE: *verify_install_types
        IMAGE:
          - "debian:bullseye-slim"
          - "debian:bookworm-slim"
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  before_script:
    - mkdir build
    - mv packages build
    - apt update
    - apt-get install -y curl

<?php foreach ([["8.1", "arm64", "aarch64"], ["7.0", "amd64", "x86_64"]] as [$major_minor, $arch, $pkgprefix]): ?>
"verify .tar.gz: [<?= $arch ?>]":
  stage: verify
  image: registry.ddbuild.io/images/mirror/debian:bullseye-slim
  tags: [ "arch:<?= $arch ?>" ]
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 2Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    PHP_VERSION: "<?= $major_minor ?>"
  needs:
    - job: "package extension: [<?= $arch ?>, <?= $pkgprefix ?>-unknown-linux-gnu]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  before_script:
    - mkdir build
    - mv packages build
  script:
    - ./dockerfiles/verify_packages/verify_tar_gz_root.sh

<?php endforeach; ?>

"verify no json ext":
  stage: verify
  image: registry.ddbuild.io/images/mirror/alpine:3.12
  tags: [ "arch:amd64" ]
  variables:
    KUBERNETES_CPU_REQUEST: 2
    KUBERNETES_MEMORY_REQUEST: 2Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
  needs:
    - job: "package extension: [amd64, x86_64-alpine-linux-musl]"
      artifacts: true
  before_script: *verify_alpine_before_script
  script:
    - ./dockerfiles/verify_packages/verify_no_ext_json.sh

"verify windows":
  stage: verify
  tags: [ "windows-v2:2019"]
  variables:
    GIT_CONFIG_COUNT: 1
    GIT_CONFIG_KEY_0: core.longpaths
    GIT_CONFIG_VALUE_0: true
  needs:
    - job: "package extension windows"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
  before_script:
    - mkdir build
    - move packages build
  script:
    - Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://community.chocolatey.org/install.ps1')) # chocolatey install
    - .\dockerfiles\verify_packages\verify_windows.ps1

"pecl tests":
  stage: verify
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_VERSION}_buster"
  tags: [ "arch:amd64" ]
  services:
    - !reference [.services, request-replayer]
    - !reference [.services, httpbin-integration]
  variables:
    KUBERNETES_CPU_REQUEST: 4
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 5Gi
  parallel:
    matrix:
      - PHP_VERSION: <?= json_encode($all_minor_major_targets), "\n" ?>
  needs:
    - job: "pecl build"
      artifacts: true
  before_script:
<?php unset_dd_runner_env_vars() ?>
    - cp ./pecl/datadog_trace-*.tgz ./datadog_trace.tgz
  script:
    - sudo pecl install datadog_trace.tgz
    - echo "extension=ddtrace.so" | sudo tee $(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}')/ddtrace.ini
    - php --ri=ddtrace
    - sudo TERM=dumb HTTPBIN_HOSTNAME=httpbin-integration HTTPBIN_PORT=8080 DATADOG_HAVE_DEV_ENV=1 DD_TRACE_GIT_METADATA_ENABLED=0 pecl run-tests --showdiff --ini=" -d datadog.trace.sources_path=" -p datadog_trace
  after_script:
    - mkdir artifacts
    - find $(pecl config-get test_dir) -type f -name '*.diff' -exec cp --parents '{}' artifacts \;
  artifacts:
    paths:
      - "artifacts/"
    when: "always"

"min install tests":
  stage: verify
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.0-shared-ext
  tags: [ "arch:amd64" ]
  variables:
    MAX_TEST_PARALLELISM: 8
    DDAGENT_HOSTNAME: 127.0.0.1
    DD_AGENT_HOST: 127.0.0.1
    DATADOG_HAVE_DEV_ENV: 1
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
  services:
    - !reference [.services, request-replayer]
    - !reference [.services, httpbin-integration]
  before_script:
    - switch-php debug
  script:
    - sudo dpkg -i packages/*amd64*.deb
    - make run_tests TESTS="-d 'extension=/opt/datadog-php/extensions/ddtrace-$(php -i | awk '/^PHP[ \t]+API[ \t]+=>/ { print $NF }')-debug.so' tests/ext" MAX_TEST_PARALLELISM=8
    - make test_c
  after_script:
    - .gitlab/collect_artifacts.sh .
  artifacts:
    paths:
      - "artifacts/"
    when: "always"

.system_tests:
  stage: verify
  image: registry.ddbuild.io/images/mirror/python:3.12-slim-bullseye
  tags: [ "docker-in-docker:amd64" ]
  variables:
    TEST_LIBRARY: php
    KUBERNETES_CPU_REQUEST: 5
    KUBERNETES_MEMORY_REQUEST: 3Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    RUST_BACKTRACE: 1
    BUILD_SH_ARGS: php
    PIP_CACHE_DIR: $CI_PROJECT_DIR/.cache/pip
    APT_CACHE: $CI_PROJECT_DIR/.cache/apt
    DOCKER_DEFAULT_PLATFORM: linux/amd64
    # TODO DD_API_KEY; SYSTEM_TESTS_AWS_ACCESS_KEY_ID; SYSTEM_TESTS_AWS_SECRET_ACCESS_KEY
  needs:
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
    - job: datadog-setup.php
      artifacts: true
    - job: "prepare code"
      artifacts: true
  before_script:
    - |
      # Setup cache dirs
      mkdir -p $PIP_CACHE_DIR
      mkdir -p $APT_CACHE/lists
      mkdir -p $APT_CACHE/archives
      chown -R $(id -u):$(id -g) $CI_PROJECT_DIR/.cache

      # Install system dependencies
      apt-get update -o dir::state::lists="$APT_CACHE/lists"
      apt-get install -y --no-install-recommends -o dir::state::lists="$APT_CACHE/lists" -o dir::cache::archives="$APT_CACHE/archives" ca-certificates curl git
      mkdir -p /etc/apt/keyrings
      curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
      apt-get update -o dir::state::lists="$APT_CACHE/lists"
      apt-get install -y --no-install-recommends -o dir::state::lists="$APT_CACHE/lists" -o dir::cache::archives="$APT_CACHE/archives" docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

      # Install Python dependencies
      pip install -U pip virtualenv
    - git clone https://github.com/DataDog/system-tests.git
    - mv packages/{datadog-setup.php,dd-library-php-*x86_64-linux-gnu.tar.gz} system-tests/binaries
    - cd system-tests
    - ./build.sh $BUILD_SH_ARGS
  cache:
    - key: v0-$CI_JOB_NAME_SLUG-cache
      when: always
      paths:
        - .cache/
  artifacts:
    paths:
      - "system-tests/logs_parametric/"
      - "system-tests/logs/"
    when: "always"

"System Tests: [default]":
  extends: .system_tests
  script:
    - ./run.sh

"System Tests":
  extends: .system_tests
  parallel:
    matrix:
      - TESTSUITE:
        - APPSEC_API_SECURITY
        - APPSEC_API_SECURITY_RC
        - APPSEC_API_SECURITY_NO_RESPONSE_BODY
        - INTEGRATIONS
        - CROSSED_TRACING_LIBRARIES
  script:
    - ./run.sh $TESTSUITE

"System Tests: [parametric]":
  extends: .system_tests
  variables:
    BUILD_SH_ARGS: "-i runner"
    PYTEST_XDIST_AUTO_NUM_WORKERS: 4
  script:
    - ./run.sh PARAMETRIC

<?php /* foreach ($arch_targets as $arch): ?>
"Loader test on <?= $arch ?> libc":
  stage: verify
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${MAJOR_MINOR}_buster"
  tags: [ "arch:$ARCH" ]
  variables:
    VALGRIND: false
    ARCH: "<?= $arch ?>"
  needs:
    - job: "package loader: [<?= $arch ?>]"
      artifacts: true
  parallel:
    matrix:
<?php if ($arch == "amd64"): ?>
      - MAJOR_MINOR:
          - "5.6"
          - "7.0"
          - "7.1"
          - "7.2"
          - "7.3"
        PHP_FLAVOUR: nts
      - MAJOR_MINOR: <?= json_encode($asan_minor_major_targets), "\n" ?>
        PHP_FLAVOUR:
          - nts
          - zts
        ARCH: amd64
        USE_VALGRIND: "true"
<?php else: ?>
      - MAJOR_MINOR:
          - "7.0"
          - "7.1"
          - "7.2"
          - "7.3"
        PHP_FLAVOUR: nts
      - MAJOR_MINOR: <?= json_encode($asan_minor_major_targets), "\n" ?>
        PHP_FLAVOUR:
          - nts
          - zts
        ARCH: arm64
<?php endif; ?>
  before_script:
    - |
     if [[ "$MINOR_MAJOR" == "8.4" ]]; then
       export XDEBUG_SO_NAME=xdebug-3.4.0.so
     elif [[ "$MINOR_MAJOR" == "8.3" ]]; then
       export XDEBUG_SO_NAME=xdebug-3.3.2.so
     elif [[ "$MINOR_MAJOR" == "8.2" ]]; then
       export XDEBUG_SO_NAME=xdebug-3.2.2.so
     elif [[ "$MINOR_MAJOR" == "8.1" ]]; then
       export XDEBUG_SO_NAME=xdebug-3.1.0.so
     elif [[ "$MINOR_MAJOR" == "8.0" ]]; then
       export XDEBUG_SO_NAME=xdebug-3.0.0.so
     elif [[ "$MINOR_MAJOR" == "7.4" ]]; then
       export XDEBUG_SO_NAME=xdebug-2.9.5.so
     fi
    - switch-php $PHP_FLAVOUR
    - tar -xzf dd-library-php-ssi-*-linux.tar.gz
    - export DD_LOADER_PACKAGE_PATH=${PWD}/dd-library-php-ssi

    - cd loader
    - mkdir -p modules
    - cp ../dd-library-php-ssi/linux-gnu/loader/dd_library_loader.so modules/
  script:
    - ./bin/test.sh

    # FIXME: Now that we strip the symbols, our suppression file is useless
    #if [[ "$MINOR_MAJOR" == "8.3" ]]; then
    #  true
    #  <<# parameters.use_valgrind >>echo "Run with Valgrind" ; TEST_USE_VALGRIND=1 ./bin/test.sh<</ parameters.use_valgrind >>
    #fi
    - ./bin/check_glibc_version.sh

"Loader test on <?= $arch ?> alpine":
  stage: verify
  image: "registry.ddbuild.io/images/mirror/alpine:3.20"
  tags: [ "arch:$ARCH" ]
  needs: [] # umm, where are these packaged?
  variables:
    ARCH: "<?= $arch ?>"
  before_script:
    - apk add --no-cache curl-dev php83 php83-dev php83-pecl-xdebug
    - export XDEBUG_SO_NAME=xdebug.so
    - rm -rf dd-library-php-ssi
    - tar -xzf dd-library-php-ssi-*-linux.tar.gz
    - export DD_LOADER_PACKAGE_PATH=${PWD}/dd-library-php-ssi
    - cd loader
    - mkdir -p modules
    - cp ../dd-library-php-ssi/linux-musl/loader/dd_library_loader.so modules/
  script:
    - ./bin/test.sh

<?php endforeach; */ ?>
