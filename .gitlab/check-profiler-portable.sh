#!/usr/bin/env bash
set -euo pipefail

project_dir="${CI_PROJECT_DIR:-$(pwd)}"
php_sdk_version="${PHP_VERSION}"
features="profiling,test,debug_stats,stack_walking_tests,tracing,tracing-subscriber,trigger_time_sample"

PHPRC='' \
  PATH="/opt/php/${php_sdk_version}/bin:${PATH}" \
  PHP_CONFIG="/opt/php/${php_sdk_version}/bin/php-config" \
  RUSTFLAGS='-C target-feature=-crt-static -C linker=musl-clang -C link-arg=/usr/lib/libunwind.a -C force-unwind-tables=yes' \
  CARGO_TARGET_DIR="${project_dir}/tmp/profiler-clippy-${PHP_VERSION}" \
  cargo clippy --all-targets --no-deps \
    --no-default-features --features "${features}" -- \
    -D warnings -Aunknown-lints
