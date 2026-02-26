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
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_bookworm-6",
        "arch" => "amd64",
        "host_os" => "linux-gnu",
    ],
    [
        "triplet" => "aarch64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_bookworm-6",
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
  - benchmarks
  - gate
  - notify
  - verify
  - shared-pipeline # OCI packaging
  - pre-release
  - release

variables:
  CARGO_HOME: "${CI_PROJECT_DIR}/.cache/cargo"

include:
  - local: .gitlab/one-pipeline.locked.yml
  - local: .gitlab/benchmarks.yml

# One pipeline job overrides
configure_system_tests:
  variables:
    SYSTEM_TESTS_SCENARIOS_GROUPS: "simple_onboarding,simple_onboarding_profiling,simple_onboarding_appsec,lib-injection,lib-injection-profiling,docker-ssi"
    ALLOW_MULTIPLE_CHILD_LEVELS: "false"

package-oci:
  needs:
<?php
foreach ($arch_targets as $arch) {
?>
    - job: "package loader: [<?= $arch ?>]"
      artifacts: true
<?php
}
?>

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
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-7.4_bookworm-6"
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

<?php foreach ($arch_targets as $arch): ?>
"aggregate tracing extension: [<?= $arch ?>]":
  stage: tracing
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-7.4_bookworm-6"
  tags: [ "arch:amd64" ]
  script: ls ./
  variables:
    GIT_STRATEGY: none
  needs:
<?php
    foreach ($build_platforms as $platform):
        if ($platform["arch"] == $arch):
            foreach ($all_minor_major_targets as $major_minor):
?>
    - job: "compile tracing extension: [<?= $major_minor ?>, <?= $arch ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
            endforeach;
        endif;
    endforeach;
?>
  artifacts:
    paths:
      - "extensions_*"
      - "standalone_*"
      - "ddtrace_*.ldflags"
<?php
endforeach;
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
    GIT_STRATEGY: none
    CONTAINER_NAME: ${CI_JOB_NAME_SLUG}-${CI_JOB_ID}
  script: |
    # Aggressive Git cleanup
    Write-Host "Performing aggressive workspace cleanup with cmd.exe..."
    cmd /c "if exist .git rmdir /s /q .git" 2>$null
    cmd /c "for /d %d in (*) do @rmdir /s /q ""%d""" 2>$null
    cmd /c "del /f /s /q *" 2>$null
    Write-Host "Cleanup complete."

    # Make sure we actually fail if a command fails
    $ErrorActionPreference = 'Stop'
    $PSNativeCommandUseErrorActionPreference = $true

    # Manual git clone with proper config
    Write-Host "Cloning repository..."
    git config --global core.longpaths true
    git config --global core.symlinks true
    git clone --branch $env:CI_COMMIT_REF_NAME $env:CI_REPOSITORY_URL .
    git checkout $env:CI_COMMIT_SHA

    # Initialize submodules
    Write-Host "Initializing submodules..."
    git submodule update --init --recursive
    Write-Host "Git setup complete."

    mkdir extensions_x86_64
    mkdir extensions_x86_64_debugsymbols

    # Start the container
    docker run -v ${pwd}:C:\Users\ContainerAdministrator\app -d --name ${CONTAINER_NAME} ${IMAGE} ping -t localhost

    # Build nts (fail fast on any step)
    docker exec ${CONTAINER_NAME} powershell.exe -Command "`$ErrorActionPreference='Stop'; `$PSNativeCommandUseErrorActionPreference=`$true; cd app; switch-php nts; & 'C:\\php\\SDK\\phpize.bat'; if (`$LASTEXITCODE -ne 0) { exit `$LASTEXITCODE }; .\\configure.bat --enable-debug-pack; if (`$LASTEXITCODE -ne 0) { exit `$LASTEXITCODE }; nmake; if (`$LASTEXITCODE -ne 0) { exit `$LASTEXITCODE }; Move-Item x64\\Release\\php_ddtrace.dll extensions_x86_64\\php_ddtrace-${ABI_NO}.dll -ErrorAction Stop; Move-Item x64\\Release\\php_ddtrace.pdb extensions_x86_64_debugsymbols\\php_ddtrace-${ABI_NO}.pdb -ErrorAction Stop"
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    # Reuse libdatadog build (fail if move fails)
    docker exec ${CONTAINER_NAME} powershell.exe -Command "`$ErrorActionPreference='Stop'; `$PSNativeCommandUseErrorActionPreference=`$true; New-Item -ItemType Directory -Force -Path 'app\\x64\\Release_TS' | Out-Null; Move-Item 'app\\x64\\Release\\target' 'app\\x64\\Release_TS\\target' -ErrorAction Stop"
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    # Build zts (fail fast on any step)
    docker exec ${CONTAINER_NAME} powershell.exe -Command "`$ErrorActionPreference='Stop'; `$PSNativeCommandUseErrorActionPreference=`$true; cd app; switch-php zts; & 'C:\\php\\SDK\\phpize.bat'; if (`$LASTEXITCODE -ne 0) { exit `$LASTEXITCODE }; .\\configure.bat --enable-debug-pack; if (`$LASTEXITCODE -ne 0) { exit `$LASTEXITCODE }; nmake; if (`$LASTEXITCODE -ne 0) { exit `$LASTEXITCODE }; Move-Item x64\\Release_TS\\php_ddtrace.dll extensions_x86_64\\php_ddtrace-${ABI_NO}-zts.dll -ErrorAction Stop; Move-Item x64\\Release_TS\\php_ddtrace.pdb extensions_x86_64_debugsymbols\\php_ddtrace-${ABI_NO}-zts.pdb -ErrorAction Stop"
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

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

<?php foreach ($arch_targets as $arch): ?>
"package loader: [<?= $arch ?>]":
  extends: .package_extension_base
  variables:
    ARCHITECTURE: "<?= ($arch == 'amd64') ? 'x86_64' : 'aarch64' ?>"
  script:
    - mkdir -p build/packages tmp/
    - ./tooling/bin/generate-ssi-package.sh $(<VERSION) "build/packages"
    - mv build/packages/ packages/
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "compile appsec helper"
      parallel:
        matrix:
          - ARCH: "<?= $arch ?>"
      artifacts: true
    - job: "compile loader: [linux-gnu, <?= $arch ?>]"
      artifacts: true
    - job: "compile loader: [linux-musl, <?= $arch ?>]"
      artifacts: true
    - job: "aggregate tracing extension: [<?= $arch ?>]"
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
    DOCKER_COMPOSE_DOWNLOAD_NAME: docker-compose-linux-x86_64
  before_script:
<?php dockerhub_login() ?>
    - apt install -y php git make curl
    - curl -L --fail https://github.com/docker/compose/releases/download/v2.36.0/${DOCKER_COMPOSE_DOWNLOAD_NAME} -o /usr/local/bin/docker-compose
    - chmod +x /usr/local/bin/docker-compose
    - mv packages/* .
    - docker network create randomized_tests_baseservices
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

# Skip until docker-in-docker:arm64 runner is available
# "randomized tests: [arm64, no-asan, <?= $i ?>]":
#   extends: .randomized_tests
#   tags: [ "docker-in-docker:arm64" ]
#   variables:
#     DOCKER_COMPOSE_DOWNLOAD_NAME: docker-compose-linux-aarch64
#   needs:
#     - job: "package extension: [arm64, aarch64-unknown-linux-gnu]"
#       artifacts: true
<?php endforeach; ?>

<?php foreach (range(1, 5) as $i): ?>

# Skip until docker-in-docker:arm64 runner is available
# "randomized tests: [arm64, asan, <?= $i ?>]":
#   extends: .randomized_tests
#   tags: [ "docker-in-docker:arm64" ]
#   variables:
#     DOCKER_COMPOSE_DOWNLOAD_NAME: docker-compose-linux-aarch64
#   needs:
#     - job: "package extension asan"
#       artifacts: true
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
<?php dockerhub_login() ?>
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
<?php dockerhub_login() ?>
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
    VERIFY_APACHE: "no"
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
<?php dockerhub_login() ?>
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
<?php dockerhub_login() ?>
    - mkdir build
    - mv packages build
    - '# Fix yum config, as centos 7 is EOL and mirrorlist.centos.org does not resolve anymore - https://serverfault.com/a/1161847'
    - sed -i s/mirror.centos.org/vault.centos.org/g /etc/yum.repos.d/*.repo
    - sed -i s/^#.*baseurl=http/baseurl=http/g /etc/yum.repos.d/*.repo
    - sed -i s/^mirrorlist=http/#mirrorlist=http/g /etc/yum.repos.d/*.repo
    - |
      # Retry yum update as vault.centos.org can be slow/unreliable
      for i in 1 2 3; do
        if yum update -y; then
          echo "yum update succeeded on attempt $i"
          break
        fi
        echo "yum update failed (attempt $i/3), retrying in 5 seconds..."
        sleep 5
        if [ $i -eq 3 ]; then
          echo "yum update failed after 3 attempts, exiting"
          exit 1
        fi
      done

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
<?php dockerhub_login() ?>
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
    GIT_CONFIG_COUNT: 2
    GIT_CONFIG_KEY_0: core.longpaths
    GIT_CONFIG_VALUE_0: true
    GIT_CONFIG_KEY_1: core.symlinks
    GIT_CONFIG_VALUE_1: true
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
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_VERSION}_bookworm-6"
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
    - pecl install datadog_trace.tgz
    - echo "extension=ddtrace.so" | sudo tee $(php -i | awk -F"=> " '/Scan this dir for additional .ini files/ {print $2}')/ddtrace.ini
    - php --ri=ddtrace
    - TERM=dumb HTTPBIN_HOSTNAME=httpbin-integration HTTPBIN_PORT=8080 DATADOG_HAVE_DEV_ENV=1 DD_TRACE_GIT_METADATA_ENABLED=0 pecl run-tests --showdiff --ini=" -d datadog.trace.sources_path=" -p datadog_trace
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
<?php dockerhub_login() ?>
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
    KUBERNETES_CPU_REQUEST: 8
    PYTEST_XDIST_AUTO_NUM_WORKERS: 8
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
      apt-get install -y --no-install-recommends -o dir::state::lists="$APT_CACHE/lists" -o dir::cache::archives="$APT_CACHE/archives" ca-certificates curl git build-essential
      mkdir -p /etc/apt/keyrings
      curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
      apt-get update -o dir::state::lists="$APT_CACHE/lists"
      apt-get install -y --no-install-recommends -o dir::state::lists="$APT_CACHE/lists" -o dir::cache::archives="$APT_CACHE/archives" docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

      # Install Python dependencies
      pip install -U pip virtualenv
<?php dockerhub_login() ?>
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
  script:
    - ./run.sh PARAMETRIC

<?php foreach ($arch_targets as $arch): ?>
"Loader test on <?= $arch ?> libc":
  stage: verify
  image: "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${MAJOR_MINOR}_${CONTAINER_SUFFIX}"
  tags: [ "arch:$ARCH" ]
  variables:
    VALGRIND: false
    ARCH: "<?= $arch ?>"
    CONTAINER_SUFFIX: bookworm-6
  needs:
    - job: "package loader: [<?= $arch ?>]"
      artifacts: true
  parallel:
    matrix:
<?php if ($arch == "amd64"): ?>
      - MAJOR_MINOR:
          - "5.6"
        PHP_FLAVOUR: nts
        CONTAINER_SUFFIX: buster
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
<?php unset_dd_runner_env_vars() ?>
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
    - mkdir extracted/
    - tar --no-same-owner --no-same-permissions --touch -xzf packages/dd-library-php-ssi-*-linux.tar.gz -C extracted/
    - export DD_LOADER_PACKAGE_PATH=${PWD}/extracted/dd-library-php-ssi

    - cd loader
    - mkdir -p modules
    - cp ${DD_LOADER_PACKAGE_PATH}/linux-gnu/loader/dd_library_loader.so modules/
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
  needs:
    - job: "package loader: [<?= $arch ?>]"
      artifacts: true
  variables:
    ARCH: "<?= $arch ?>"
  before_script:
<?php unset_dd_runner_env_vars() ?>
    - apk add --no-cache curl-dev php83 php83-dev php83-pecl-xdebug bash
    - export XDEBUG_SO_NAME=xdebug.so
    - rm -rf dd-library-php-ssi
    - tar -xzf packages/dd-library-php-ssi-*-linux.tar.gz
    - export DD_LOADER_PACKAGE_PATH=${PWD}/dd-library-php-ssi
    - cd loader
    - mkdir -p modules
    - cp ../dd-library-php-ssi/linux-musl/loader/dd_library_loader.so modules/
  script:
    - ./bin/test.sh

<?php endforeach; ?>

"publish to public s3":
  stage: release
  image: registry.ddbuild.io/images/mirror/amazon/aws-cli:2.17.32
  tags: [ "arch:amd64" ]
  rules:
    - if: $CI_COMMIT_REF_NAME == "master" && $CI_PIPELINE_SOURCE != "schedule"
      when: on_success
    - when: manual
      allow_failure: true
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "datadog-setup.php"
      artifacts: true
# Maybe use a different base name for these
#    - job: "package extension asan"
#      artifacts: true
    - job: "package extension windows"
      artifacts: true
<?php
foreach ($build_platforms as $platform) {
?>
    - job: "package extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php
}
foreach ($arch_targets as $arch) {
?>
    - job: "package loader: [<?= $arch ?>]"
      artifacts: true
<?php
}
?>
  interruptible: false
  variables:
    GIT_STRATEGY: none
  script: |
    set -e
    VERSION="$(<VERSION)"
    [[ -z "${VERSION}" ]] && echo "VERSION file is empty or not present" && exit 1
    cd packages/ && aws s3 cp --recursive . "s3://dd-trace-php-builds/${VERSION}/"
    if [ "${CI_COMMIT_REF_NAME}" = "${CI_DEFAULT_BRANCH}" ]; then
      aws s3 cp datadog-setup.php "s3://dd-trace-php-builds/latest/"
    else
      echo "Skipping latest upload for non-default branch: ${CI_COMMIT_REF_NAME} (default: ${CI_DEFAULT_BRANCH})"
    fi
    echo "https://s3.us-east-1.amazonaws.com/dd-trace-php-builds/$(echo $VERSION | sed 's/+/%2B/')/datadog-setup.php"
  artifacts:
    paths:
      - packages/datadog-setup.php

"bundle for reliability env":
  stage: shared-pipeline
  image: registry.ddbuild.io/ci/libdatadog-build/ci_docker_base:67145216
  tags: [ "runner:main", "size:large" ]
  rules:
    - if: $CI_PIPELINE_SOURCE == "schedule" && $NIGHTLY
      when: on_success
    - if: $CI_COMMIT_REF_NAME =~ /^ddtrace-/
      when: on_success
    - when: manual
      allow_failure: true
  needs:
    - job: "prepare code"
      artifacts: true
    - job: "datadog-setup.php"
      artifacts: true
    - job: "package extension: [amd64, x86_64-unknown-linux-gnu]"
      artifacts: true
  script:
    - |
      if [ "$CI_COMMIT_REF_NAME" = "master" ]; then
        echo UPSTREAM_TRACER_VERSION=dev-master > upstream.env
      else
        echo "UPSTREAM_TRACER_VERSION=$(<VERSION)" > upstream.env
      fi
    - mv packages/dd-library-php-*-x86_64-linux-gnu.tar.gz dd-library-php-x86_64-linux-gnu.tar.gz
    - tar -cf 'datadog-setup-x86_64-linux-gnu.tar' 'datadog-setup.php' 'dd-library-php-x86_64-linux-gnu.tar.gz'
  artifacts:
    paths:
      - 'upstream.env'
      - 'datadog-setup-x86_64-linux-gnu.tar'

deploy_to_reliability_env:
  stage: shared-pipeline
  allow_failure: true
  needs:
    - job: "bundle for reliability env"
  rules:
   - when: on_success
  trigger:
    project: DataDog/apm-reliability/datadog-reliability-env
    branch: $RELIABILITY_ENV_BRANCH
  variables:
    UPSTREAM_PACKAGE_JOB: "bundle for reliability env"
    UPSTREAM_PROJECT_ID: $CI_PROJECT_ID
    UPSTREAM_PROJECT_NAME: $CI_PROJECT_NAME
    UPSTREAM_PIPELINE_ID: $CI_PIPELINE_ID
    UPSTREAM_BRANCH: $CI_COMMIT_REF_NAME
    UPSTREAM_COMMIT_SHA: $CI_COMMIT_SHA

"generate github token":
  stage: pre-release
  image: registry.ddbuild.io/images/dd-octo-sts-ci-base:2025.06-1
  tags: [ "arch:amd64" ]
  only:
    refs:
      - /^ddtrace-.*$/
  needs:
    - job: "datadog-setup.php"
      artifacts: false
    - job: "package extension windows"
      artifacts: false
<?php foreach ($build_platforms as $platform): ?>
    - job: "package extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: false
<?php endforeach; ?>
  id_tokens:
    DDOCTOSTS_ID_TOKEN:
      aud: dd-octo-sts
  script:
    - echo "Generating GitHub token for release..."
    - dd-octo-sts debug --scope DataDog/dd-trace-php --policy gitlab-ci-publish-release
    - dd-octo-sts token --scope DataDog/dd-trace-php --policy gitlab-ci-publish-release > github_token.txt
    # Verify token works
    - export GITHUB_TOKEN=$(cat github_token.txt)
    - 'curl -f -H "Authorization: token $GITHUB_TOKEN" https://api.github.com/repos/DataDog/dd-trace-php | jq -r .name'
    - echo "Token generated and verified successfully"
  artifacts:
    paths:
      - github_token.txt
    expire_in: 1 hour
    when: on_success
  variables:
    # Prevent token from appearing in logs
    GITHUB_TOKEN: "[MASKED]"

"upload SSI debug symbols":
  stage: pre-release
  image: registry.ddbuild.io/ci/async-profiler-build:v71888475-datadog-ci
  tags: [ "arch:amd64" ]
  only:
    - tags
  needs:
<?php
foreach ($arch_targets as $arch) {
?>
    - job: "package loader: [<?= $arch ?>]"
      artifacts: true
<?php
}
?>
  before_script:
    - mkdir build
    - find packages -name "*.tar.gz" -exec tar xzf {} -C build/ \;
  script:
    - export DATADOG_API_KEY_PROD=$(aws ssm get-parameter --region us-east-1 --name ci.async-profiler-build.api_key_public_symbols_prod_us1 --with-decryption --query "Parameter.Value" --out text)
    - export DATADOG_API_KEY_STAGING=$(aws ssm get-parameter --region us-east-1 --name ci.async-profiler-build.api_key_public_symbols_staging --with-decryption --query "Parameter.Value" --out text)
    - DATADOG_API_KEY=$DATADOG_API_KEY_STAGING DATADOG_SITE=datad0g.com DD_BETA_COMMANDS_ENABLED=1 datadog-ci elf-symbols upload --disable-git ./build
    - DATADOG_API_KEY=$DATADOG_API_KEY_PROD DATADOG_SITE=datadoghq.com DD_BETA_COMMANDS_ENABLED=1 datadog-ci elf-symbols upload --disable-git ./build

"publish release to github":
  stage: release
  image: registry.ddbuild.io/images/mirror/php:8.2-cli
  tags: [ "arch:amd64" ]
  variables: # enough memory for the individual artifacts
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 5Gi
  only:
    refs:
      - /^ddtrace-.*$/
  needs:
    - job: "generate github token"
      artifacts: true
    - job: "datadog-setup.php"
      artifacts: true
    - job: "package extension windows"
      artifacts: true
<?php foreach ($build_platforms as $platform): ?>
    - job: "package extension: [<?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]"
      artifacts: true
<?php endforeach; ?>
  script:
    - echo "Using pre-generated GitHub token for release..."
    - export GITHUB_RELEASE_PAT=$(cat github_token.txt)
    - php -d memory_limit=4G tooling/ci/create_release.php packages
  after_script:
    # Clean up token file (token will expire automatically in 1 hour)
    - rm -f github_token.txt
  variables:
    # Prevent token from appearing in logs
    GITHUB_RELEASE_PAT: "[MASKED]"
