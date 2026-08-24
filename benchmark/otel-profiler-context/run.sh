#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
IMAGE=${IMAGE:-ddtrace-otel-profiler-context-benchmark:php83}
VOLUME=${VOLUME:-ddtrace-otel-profiler-context-benchmark}
CPU=${CPU:-2}
MEMORY=${MEMORY:-4g}
BUILD_MEMORY=${BUILD_MEMORY:-12g}
COMMAND=${1:-run}

container() {
    docker run --rm \
        --init \
        --cpuset-cpus="$CPU" \
        --memory="$MEMORY" \
        -v "$VOLUME:/work" \
        "$@"
}

build_image() {
    docker build \
        -t "$IMAGE" \
        -f "$ROOT/benchmark/otel-context-switch/Dockerfile" \
        "$ROOT/benchmark/otel-context-switch"
}

ensure_image() {
    docker image inspect "$IMAGE" >/dev/null 2>&1 || build_image
}

sync_source() {
    docker volume create "$VOLUME" >/dev/null
    docker run --rm \
        -v "$ROOT:/source:ro" \
        -v "$VOLUME:/work" \
        "$IMAGE" \
        rsync -a --delete \
            --exclude=.git \
            --exclude=.cargo-registry \
            --exclude=target \
            --exclude=tmp \
            /source/ /work/
}

build_extensions() {
    sync_source
    docker run --rm \
        --memory="$BUILD_MEMORY" \
        -v "$VOLUME:/work" \
        "$IMAGE" \
        sh -c '
            make -C /work -j"$(nproc)" all \
                "CFLAGS=-O2 -g0 -DNDEBUG -Wall -Wextra"
            make -C /work compile_profiler
        '
}

revision() {
    git -C "$ROOT" rev-parse --verify HEAD | tr -d '\n'
    if ! git -C "$ROOT" diff --quiet || ! git -C "$ROOT" diff --cached --quiet; then
        printf '%s' '-dirty'
    fi
}

benchmark_env=(
    -e DD_SERVICE=benchmark-base-service
    -e DD_ENV=benchmark-base-environment
    -e DD_VERSION=benchmark-base-version
    -e DD_TRACE_ENABLED=1
    -e DD_TRACE_AUTO_FLUSH_ENABLED=0
    -e DD_TRACE_GENERATE_ROOT_SPAN=0
    -e DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
    -e DD_DATA_STREAMS_ENABLED=0
    -e DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
    -e DD_PROFILING_ENABLED=1
    -e DD_PROFILING_WALLTIME_ENABLED=0
    -e DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=0
    -e DD_PROFILING_ALLOCATION_ENABLED=0
    -e DD_PROFILING_EXCEPTION_ENABLED=0
    -e DD_PROFILING_TIMELINE_ENABLED=0
    -e DD_PROFILING_OUTPUT_PPROF=/tmp/ddprof-benchmark
    -e DD_REMOTE_CONFIG_ENABLED=0
    -e DD_TRACE_HEALTH_METRICS_ENABLED=0
    -e DD_TRACE_STARTUP_LOGS=0
    -e DD_TRACE_LOG_LEVEL=off
)

php_command=(
    php -n
    -d extension=/work/tmp/build_extension/modules/ddtrace.so
    -d extension=/work/tmp/build_profiler/release/libdatadog_php_profiling.so
    /work/benchmark/otel-profiler-context/workload.php
)

run_benchmark() {
    local mode=${1:-overrides}
    local epochs=${2:-${EPOCHS:-20}}
    local iterations=${3:-${ITERATIONS:-50000}}

    local status=0
    container \
        -e "GIT_REVISION=$(revision)" \
        "${benchmark_env[@]}" \
        -e EPOCHS -e ITERATIONS -e WARMUP_ITERATIONS \
        "$IMAGE" \
        "${php_command[@]}" "$mode" "$epochs" "$iterations" || status=$?
    if [[ $status -ne 0 && $status -ne 137 ]]; then
        return "$status"
    fi
}

case "$COMMAND" in
    image)
        build_image
        ;;
    sync)
        ensure_image
        sync_source
        ;;
    build)
        ensure_image
        build_extensions
        ;;
    run)
        shift || true
        if [[ $# -gt 0 ]]; then
            run_benchmark "$@"
        else
            run_benchmark inactive
            run_benchmark matching
            run_benchmark overrides
        fi
        ;;
    perf)
        shift || true
        mode=${1:-overrides}
        iterations=${2:-${ITERATIONS:-50000}}
        status=0
        container \
            --cap-add PERFMON \
            -e "GIT_REVISION=$(revision)" \
            "${benchmark_env[@]}" \
            "$IMAGE" \
            perf stat -e cycles,instructions,branches,branch-misses,cache-misses \
            "${php_command[@]}" "$mode" 1 "$iterations" || status=$?
        if [[ $status -ne 0 && $status -ne 137 ]]; then
            exit "$status"
        fi
        ;;
    clean)
        docker volume rm -f "$VOLUME"
        ;;
    *)
        echo "usage: $0 {image|sync|build|run [MODE [EPOCHS [ITERATIONS]]]|perf [MODE [ITERATIONS]]|clean}" >&2
        exit 2
        ;;
esac
