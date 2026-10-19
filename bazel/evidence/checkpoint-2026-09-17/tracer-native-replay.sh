#!/bin/sh
set -u

execroot=/home/bits/.cache/dd-php-tracer-focused-output/1cd47a7d98cd6a0b69107a6e1d9b301e/execroot/_main
marker=${1:-/tmp/dd-checkpoint-tracer-native-replay.passed}
log=${2:-/tmp/dd-checkpoint-tracer-native-replay.log}

cd "$execroot"
/usr/bin/env -i \
    DD_APPSEC_ENABLED=false \
    DD_PROFILING_ENABLED=false \
    DD_TRACE_ENABLED=false \
    HERMETIC_EXEC_RUNTIME_ROOT=external/+execution_runtimes+exec_runtime_debian12_x86_64/root/lib/x86_64-linux-gnu/../.. \
    HERMETIC_LLVM_ROOT=external/+http_archive+llvm_20_1_4_dist_x86_64 \
    HERMETIC_TOOLS_ROOT=external/+execution_tools+exec_tools_alpine322_x86_64/root/bin/.. \
    HOME=/nonexistent \
    LANG=C \
    LC_ALL=C \
    PATH=bazel-out/k8-opt-exec/bin/bazel/dependencies/exec_tools/cmake_x86_64/bin:bazel-out/k8-fastbuild-ST-f926a7de0408/bin/bazel/dependencies/exec_tools:bazel-out/k8-opt-exec/bin/bazel/dependencies/exec_tools \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONHASHSEED=0 \
    PYTHONNOUSERSITE=1 \
    TZ=UTC \
    bazel-out/k8-opt-exec/bin/bazel/dependencies/exec_tools/sh \
    bazel/products/tracer/fat/native-smoke.sh \
    external/+php_exec_oci_imports+php_exec_bookworm_amd64/runtime/lib/ld.so \
    external/+php_exec_oci_imports+php_exec_bookworm_amd64/runtime/lib:external/+target_curl_oci+target_curl_centos7_amd64/sdk/dependencies/openssl/lib:external/+target_curl_oci+target_curl_centos7_amd64/sdk/dependencies/openssl/lib/engines-1.1:external/+target_curl_oci+target_curl_centos7_amd64/sdk/dependencies/zlib/lib:external/+target_curl_oci+target_curl_centos7_amd64/sdk/lib \
    external/+php_exec_oci_imports+php_exec_bookworm_amd64/runtime/bin/php \
    bazel-out/k8-fastbuild-ST-f926a7de0408/bin/bazel/products/tracer/ddtrace_fat_amd64_glibc_php85/ddtrace.so \
    8.5.8RC1 \
    VERSION \
    "$marker" \
    bazel/products/tracer/fat/sidecar-direct-smoke.py \
    bazel-out/k8-fastbuild-ST-f926a7de0408/bin/rust_sidecar_ping_client \
    >"$log" 2>&1
status=$?
printf 'COMMAND_EXIT_CODE=%s\n' "$status" >>"$log"
exit "$status"
