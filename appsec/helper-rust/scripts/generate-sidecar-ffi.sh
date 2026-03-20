#!/bin/bash
# Generate sidecar FFI bindings using bindgen
#
# Usage: ./scripts/generate-sidecar-ffi.sh
#
# Requires: cargo install bindgen-cli

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
COMPONENTS_RS_DIR="$PROJECT_DIR/../../components-rs"
OUTPUT_FILE="$PROJECT_DIR/src/ffi/sidecar_ffi.rs"

cd "$COMPONENTS_RS_DIR"

bindgen sidecar.h \
    --allowlist-function 'ddog_sidecar_enqueue_telemetry_log' \
    --allowlist-function 'ddog_sidecar_enqueue_telemetry_point' \
    --allowlist-function 'ddog_sidecar_enqueue_telemetry_metric' \
    --allowlist-function 'ddog_sidecar_connect' \
    --allowlist-function 'ddog_sidecar_ping' \
    --allowlist-function 'ddog_sidecar_transport_drop' \
    --allowlist-function 'ddog_Error_drop' \
    --allowlist-function 'ddog_Error_message' \
    --allowlist-function 'ddog_MaybeError_drop' \
    --allowlist-type 'ddog_SidecarTransport' \
    --allowlist-type 'ddog_LogLevel' \
    --allowlist-type 'ddog_CharSlice' \
    --allowlist-type 'ddog_Slice_CChar' \
    --allowlist-type 'ddog_MaybeError' \
    --allowlist-type 'ddog_Option_Error' \
    --allowlist-type 'ddog_Option_Error_Tag' \
    --allowlist-type 'ddog_Error' \
    --allowlist-type 'ddog_Vec_U8' \
    --allowlist-type 'ddog_MetricNamespace' \
    --no-layout-tests \
    --no-doc-comments \
    --use-core \
    --raw-line '//! Auto-generated FFI bindings from components-rs/sidecar.h' \
    --raw-line '//!' \
    --raw-line '//! Regenerate with: ./scripts/generate-sidecar-ffi.sh' \
    --raw-line '//!' \
    --raw-line '//! Only includes types/functions needed for helper-rust as telemetry sender.' \
    --raw-line '#![allow(non_camel_case_types, non_upper_case_globals, dead_code)]' \
    --output "$OUTPUT_FILE" \
    -- -I.

echo "Generated $OUTPUT_FILE"
