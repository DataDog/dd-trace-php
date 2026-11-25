#!/usr/bin/env bash
# Crash PHP on purpose and verify that .gitlab/collect-crashes.sh collects a
# core dump and produces a symbolized GDB backtrace.

set -euo pipefail

fail() {
    echo "coredump smoke test failed: $*" >&2
    exit 1
}

out_dir="${CI_PROJECT_DIR:-$PWD}/artifacts/core-dumps"
rm -rf "${out_dir}"

command -v gdb >/dev/null 2>&1 || fail "gdb is not installed"
command -v file >/dev/null 2>&1 || fail "file is not installed"
gdb --version | sed -n '1p'

ulimit -c unlimited
echo "core ulimit: $(ulimit -c)"

marker="$(mktemp -u "${TMPDIR:-/tmp}/coredump-smoke.XXXXXX")"
pid=""
cleanup() {
    if [ -n "${pid}" ]; then
        kill -KILL "${pid}" 2>/dev/null || true
    fi
    rm -f "${marker}"
}
trap cleanup EXIT

# Spin inside the PHP VM rather than sleeping in libc so GDB unwinds PHP frames
# even on Alpine where stripped musl lacks unwind tables.
php -d max_execution_time=10 -r 'touch($argv[1]); for ($i = 0; ; $i++) {}' "${marker}" &
pid=$!

waited=0
while [ ! -e "${marker}" ]; do
    if ! kill -0 "${pid}" 2>/dev/null; then
        fail "php exited before creating readiness marker"
    fi
    if [ "${waited}" -ge 50 ]; then
        fail "timed out waiting for php readiness marker"
    fi
    sleep 0.1
    waited=$((waited + 1))
done

kill -SEGV "${pid}"
rc=0
wait "${pid}" || rc=$?
pid=""
[ "${rc}" -eq 139 ] || fail "expected php to die with SIGSEGV (exit 139), got exit ${rc}"

"$(dirname "$0")/collect-crashes.sh" --out-dir "${out_dir}"

shopt -s nullglob
backtraces=("${out_dir}"/*.bt.txt)
cores=("${out_dir}"/*.gz)

[ "${#cores[@]}" -gt 0 ] || fail "no core dump archive collected in ${out_dir}"
[ "${#backtraces[@]}" -gt 0 ] || fail "no backtrace file collected in ${out_dir}"

grep -lE 'execute_ex|zend_execute|do_cli' "${backtraces[@]}" >/dev/null \
    || fail "no backtrace in ${out_dir} contains expected PHP frames"

echo "coredump smoke test passed (${#cores[@]} core(s), ${#backtraces[@]} backtrace(s))"
