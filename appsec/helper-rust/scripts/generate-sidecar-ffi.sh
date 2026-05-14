#!/bin/bash
# Generate sidecar FFI bindings using bindgen via the php-deps Docker image.
#
# Usage: ./scripts/generate-sidecar-ffi.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
REPO_DIR="$(cd "$PROJECT_DIR/../.." && pwd)"

# Paths as seen inside the container (repo mounted at /project)
COMPONENTS_RS_INNER=/project/components-rs
OUTPUT_FILE_INNER=/project/appsec/helper-rust/src/ffi/sidecar_ffi.rs

docker run --init --rm \
    --entrypoint /bin/sh \
    --mount type=bind,src="$REPO_DIR",dst=/project \
    --mount type=volume,src=php-tracer-cargo-cache,dst=/usr/local/cargo/registry \
    --mount type=volume,src=php-tracer-cargo-cache-git,dst=/usr/local/cargo/git \
    datadog/dd-appsec-php-ci:php-deps \
    -e -c "
        command -v bindgen >/dev/null 2>&1 || cargo install bindgen-cli --locked -q
        cd $COMPONENTS_RS_INNER
        bindgen sidecar.h \
            --allowlist-function 'ddog_sidecar_appsec_register_message_handler' \
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
            --allowlist-function 'ddog_sidecar_send_appsec_message' \
            --allowlist-function 'ddog_sidecar_appsec_response_drop' \
            --allowlist-type 'ddog_AppsecCResponse' \
            --allowlist-type 'ddog_OnMessageFn' \
            --allowlist-type 'ddog_OnDisconnectFn' \
            --allowlist-type 'ddog_FreeResponseFn' \
            --allowlist-type 'ddog_MetricType' \
            --output $OUTPUT_FILE_INNER \
            -- -I.
    "

echo "Generated $PROJECT_DIR/src/ffi/sidecar_ffi.rs"
