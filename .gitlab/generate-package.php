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

variables:
  CARGO_HOME: "${CI_PROJECT_DIR}/.cache/cargo"

"Prepare Code":
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

"Cache Cargo Deps":
  stage: prepare
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  parallel:
    matrix:
      - TRIPLET: x86_64-alpine-linux-musl
        IMAGE: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-8.1
        ARCH: amd64
      - TRIPLET: aarch64-alpine-linux-musl
        IMAGE: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-compile-extension-alpine-8.1
        ARCH: arm64
      - TRIPLET: x86_64-unknown-linux-gnu
        IMAGE: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:centos-7
        ARCH: amd64
      - TRIPLET: aarch64-unknown-linux-gnu
        IMAGE: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:centos-7
        ARCH: arm64
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
foreach ($build_platforms as $platform) {
    foreach ($php_versions_to_abi as $major_minor => $abi_no) {
        $image = sprintf($platform['image_template'], $major_minor);
?>
"cargo build release: [<?= $major_minor ?>, <?= $platform['arch'] ?>, <?= $platform['triplet'] ?>]":
  stage: profiler
  image: $IMAGE
  tags: [ "arch:$ARCH" ]
  variables:
    PLATFORM: "<?= $platform['triplet'] ?>"
    IMAGE: "<?= $image ?>"
    TRIPLET: "<?= $platform['triplet'] ?>"
    ARCH: "<?= $platform['arch'] ?>"
    ABI_NO: "<?= $abi_no ?>"
  script:
    - .gitlab/append-build-id.sh
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
