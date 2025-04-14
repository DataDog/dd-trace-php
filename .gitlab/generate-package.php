<?php

$php_versions_to_abi = [
    "7.0" => "20151012",
    "7.1" => "20160303",
    "7.2" => "20170718",
    "7.3" => "20180731",
    "7.4" => "20190902",
    "8.0" => "20200930",
    "8.1" => "20210902",
    "8.2" => "20220829",
    "8.3" => "20230831",
    "8.4" => "20240924",
];

$build_platforms = [
    [
        "triplet" => "x86_64-alpine-linux-musl",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-%s",
        "arch" => "amd64",
        "host_os" => "linux-musl",
    ],
    [
      "triplet" => "aarch64-alpine-linux-musl",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-%s",
        "arch" => "arm64",
        "host_os" => "linux-musl",
    ],
    [
        "triplet" => "x86_64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_centos-7",
        "arch" => "amd64",
        "host_os" => "linux-gnu",
    ],
    [
        "triplet" => "aarch64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_centos-7",
        "arch" => "arm64",
        "host_os" => "linux-gnu",
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

$asan_php_versions = [
    "7.4",
    "8.0",
    "8.1",
    "8.2",
    "8.3",
    "8.4",
];

$windows_build_platforms = [
    [
        "triplet" => "x86_64-pc-windows-msvc",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_windows",
        "arch" => "amd64",
        "host_os" => "windows-msvc",
    ],
];

$windows_php_versions = [
    "7.2",
    "7.3",
    "7.4",
    "8.0",
    "8.1",
    "8.2",
    "8.3",
    "8.4",
];
?>


stages:
  - prepare
  - profiler
  - appsec
  - tracing
  - packaging

variables:
  CARGO_HOME: "${CI_PROJECT_DIR}/.cache/cargo"

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
    foreach ($php_versions_to_abi as $major_minor => $abi_no) {
        if ($major_minor == "7.0") {
            continue;
        }
        $image = sprintf($platform['image_template'], $major_minor);
?>
"cargo build release: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: profiler
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
} else {
?>
    - |
      if [ ! -d "/opt/cmake/3.24.4" ]
      then
        cd /tmp && curl -OL https://github.com/Kitware/CMake/releases/download/v3.24.4/cmake-3.24.4-Linux-$(uname -m).tar.gz
        mkdir -p /opt/cmake/3.24.4
        cd /opt/cmake/3.24.4 && tar -xf /tmp/cmake-3.24.4-Linux-$(uname -m).tar.gz --strip 1
        echo 'export PATH="/opt/cmake/3.24.4/bin:$PATH"' >> "$BASH_ENV"
        cd "${CI_PROJECT_DIR}"
      fi
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
    CARGO_BUILD_JOBS: 12
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
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
    foreach ($asan_php_versions as $major_minor) {
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
    foreach ($windows_php_versions as $major_minor) {
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
    CONTAINER_NAME: $CI_JOB_NAME_SLUG
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

<?php
foreach ($build_platforms as $platform) {
?>
"package extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: packaging
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php_fpm_packaging
  tags: [ "arch:amd64" ]
  script: ./.gitlab/package-extension.sh
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "pecl build"
      artifacts: true

    # Loader
    - job: "compile loader: [<?= $platform['host_os'] ?>, <?= $platform['arch'] ?>]"
      artifacts: true

    # Link tracing extension
    - job: "link tracing extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true

    # Compile tracing sidecar
    - job: "compile tracing sidecar: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true

    # Compile appsec helper
    - job: "compile appsec helper"
      parallel:
        matrix:
          - ARCH: "<?= $platform['arch'] ?>"
      artifacts: true

<?php
    foreach ($php_versions_to_abi as $major_minor => $abi_no) {
        if ($major_minor == "7.0") {
            continue;
        }
?>
    # Profiler extension
    - job: "cargo build release: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
    }
?>
  variables:
    MAKE_JOBS: 9
  artifacts:
    paths:
      - "packages/"
      - "packages.tar.gz"
      - "pecl/"
<?php
}
?>

"package extension windows":
  stage: packaging
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php_fpm_packaging
  tags: [ "arch:amd64" ]
  script: ./.gitlab/package-extension.sh
  needs:
    - job: "prepare code"
      artifacts: true
<?php
foreach ($windows_php_versions as $major_minor) {
?>
    - job: "compile extension windows: [<?= $major_minor ?>]"
      artifacts: true
<?php
}
?>
  script: ./.gitlab/package-extension.sh
  artifacts:
    paths:
      - "packages/"
      - "packages.tar.gz"


"package extension asan":
  stage: packaging
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php_fpm_packaging
  tags: [ "arch:amd64" ]
  script: ./.gitlab/package-extension.sh
  needs:
    - job: "prepare code"
      artifacts: true
<?php
foreach ($asan_build_platforms as $platform) {
    foreach ($asan_php_versions as $major_minor) {
        $abi_no = $php_versions_to_abi[$major_minor];
?>
    - job: "compile tracing extension asan: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
    }
}
?>
  variables:
    MAKE_JOBS: 9
  artifacts:
    paths:
      - "packages/"
      - "packages.tar.gz"
      - "pecl/"
