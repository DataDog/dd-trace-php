#!/usr/bin/env bash
# Sourced by the production build scripts; empty configuration is a no-op.
measure_legacy() {
    local category="$1" identity="$2"
    shift 2
    if [[ -n "${BAZEL_LEGACY_MEASURE_BIN:-}" ]]; then
        "${BAZEL_LEGACY_MEASURE_BIN}" "${BAZEL_LEGACY_MEASURE_DIR}/${identity}.json" "$category" "$identity" "$@"
    else
        "$@"
    fi
}
