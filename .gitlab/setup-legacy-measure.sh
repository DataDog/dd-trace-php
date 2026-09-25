#!/usr/bin/env bash
# Source immediately before the existing release command in measured CI jobs.
set -e
export BAZEL_LEGACY_MEASURE_DIR="${CI_PROJECT_DIR:-$PWD}/artifacts/bazel/legacy/${CI_JOB_ID:?}"
mkdir -p "$BAZEL_LEGACY_MEASURE_DIR"
export BAZEL_LEGACY_MEASURE_BIN="${CI_PROJECT_DIR:-$PWD}/artifacts/bazel/legacy-measure-${CI_JOB_ID}"
cc -std=gnu11 -O2 -o "$BAZEL_LEGACY_MEASURE_BIN" tools/bazel/legacy-measure.c
{
    printf 'commit=%s\n' "${CI_COMMIT_SHA:-}"
    printf 'pipeline=%s\n' "${CI_PIPELINE_ID:-}"
    printf 'job=%s\n' "${CI_JOB_ID:-}"
    printf 'image=%s\n' "${IMAGE:-${CI_JOB_IMAGE:-}}"
    printf 'arch=%s\n' "${ARCH:-}"
    printf 'triplet=%s\n' "${TRIPLET:-}"
    printf 'php_version=%s\n' "${PHP_VERSION:-}"
    printf 'php_abi=%s\n' "${ABI_NO:-}"
    printf 'sdk_version=%s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    printf 'host_os=%s\n' "${HOST_OS:-}"
    printf 'version_sha256=%s\n' "$(sha256sum VERSION | cut -d ' ' -f 1)"
    printf 'bridge_sha256=%s\n' "$(sha256sum src/bridge/_generated*.php | sha256sum | cut -d ' ' -f 1)"
    # Legacy jobs consume prepared sources and do not initialize submodule
    # repositories. Read the pinned gitlink revisions from the parent commit.
    printf 'libdatadog=%s\n' "$(git rev-parse HEAD:libdatadog)"
    printf 'libddwaf=%s\n' "$(git rev-parse HEAD:appsec/third_party/libddwaf-rust)"
    printf 'cpu_request=%s\n' "${KUBERNETES_CPU_REQUEST:-}"
    printf 'make_jobs=%s\n' "${MAKE_JOBS:-}"
    printf 'cargo_build_jobs=%s\n' "${CARGO_BUILD_JOBS:-}"
    printf 'cargo_home=%s\n' "${CARGO_HOME:-}"
    printf 'cargo_lock_sha256=%s\n' "$(sha256sum Cargo.lock | cut -d ' ' -f 1)"
    printf 'cargo_cache_policy=%s\n' "$(if [[ -n "${CARGO_BUILD_JOBS:-}" ]]; then printf pull; else printf none; fi)"
} > "$BAZEL_LEGACY_MEASURE_DIR/metadata.txt"
