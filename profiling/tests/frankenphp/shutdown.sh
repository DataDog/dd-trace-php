#!/usr/bin/env bash

set -euo pipefail

test_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
output_dir=${1:-artifacts/frankenphp}
mkdir -p "$output_dir"
output_dir=$(cd -- "$output_dir" && pwd)
server_pid=
server_log=

cleanup() {
    if [[ -n "$server_pid" ]]; then
        kill -KILL "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
}
trap cleanup EXIT

fail() {
    echo "$*" >&2
    cat "$server_log" >&2
    exit 1
}

# The regression requires dlclose to unload the profiler before php-main exits.
unset ZEND_DONT_UNLOAD_MODULES
ulimit -c 0

for mode in classic worker; do
    for run in 1 2 3; do
        case_dir=$(mktemp -d "$output_dir/$mode-$run.XXXXXX")
        server_log="$case_dir/frankenphp.log"
        worker_config=
        if [[ "$mode" == worker ]]; then
            worker_config="worker \"$test_dir/index.php\" 2"
        fi
        cat > "$case_dir/Caddyfile" <<EOF
{
    admin off
    auto_https off
    frankenphp {
        num_threads 4
        $worker_config
    }
}
http://127.0.0.1:8080 {
    root * "$test_dir"
    php_server
}
EOF
        DD_TEST_FRANKENPHP_MODE="$mode" \
        DD_PROFILING_ENABLED=1 \
        DD_PROFILING_OUTPUT_PPROF="$case_dir/profile" \
        DD_TRACE_ENABLED=1 \
        DD_TRACE_AGENT_URL=http://127.0.0.1:9 \
        DD_TRACE_FORCE_FLUSH_ON_SIGTERM=1 \
        DD_REMOTE_CONFIG_ENABLED=0 \
        DD_INSTRUMENTATION_TELEMETRY_ENABLED=0 \
            frankenphp run --config "$case_dir/Caddyfile" > "$server_log" 2>&1 &
        server_pid=$!

        ready=false
        for attempt in {1..100}; do
            if response=$(curl --silent --show-error --max-time 1 \
                http://127.0.0.1:8080/ 2> "$case_dir/curl.log"); then
                [[ "$response" == ok ]] || fail "$mode/$run: unexpected response: $response"
                ready=true
                break
            fi
            kill -0 "$server_pid" 2>/dev/null || fail "$mode/$run: server exited before the request"
            sleep 0.1
        done
        [[ "$ready" == true ]] || fail "$mode/$run: server did not become ready"

        kill -TERM "$server_pid"
        for attempt in {1..200}; do
            kill -0 "$server_pid" 2>/dev/null || break
            sleep 0.1
        done
        if kill -0 "$server_pid" 2>/dev/null; then
            fail "$mode/$run: server did not exit within 20 seconds of SIGTERM"
        fi
        if wait "$server_pid"; then
            server_pid=
        else
            status=$?
            server_pid=
            fail "$mode/$run: server exited with status $status after SIGTERM"
        fi
        compgen -G "$case_dir/profile.*" > /dev/null || fail "$mode/$run: no profile was produced"
        echo "$mode/$run: profiling enabled, SIGTERM exit status 0"
    done
done
