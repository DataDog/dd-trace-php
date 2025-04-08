<?php

$php_versions_to_abi = [
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
    ],
    [
      "triplet" => "aarch64-alpine-linux-musl",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-%s",
        "arch" => "arm64",
    ],
    [
        "triplet" => "x86_64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_centos-7",
        "arch" => "amd64",
    ],
    [
        "triplet" => "aarch64-unknown-linux-gnu",
        "image_template" => "registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-%s_centos-7",
        "arch" => "arm64",
    ]
];
?>


stages:
  - prepare
  - profiler
  - appsec

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
    PLATFORM: "<?= $platform['triplet'] ?>"
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
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
    PLATFORM: "<?= $platform['triplet'] ?>"
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
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
    GIT_SUBMODULE_STRATEGY: recursive
    GIT_SUBMODULE_UPDATE_FLAGS: --remote --jobs 4
    GIT_SUBMODULE_PATHS: libdatadog appsec/third_party/cpp-base64 appsec/third_party/libddwaf appsec/third_party/msgpack-c
  script: .gitlab/build-appsec-helper.sh
  artifacts:
    paths:
      - "appsec_*"
