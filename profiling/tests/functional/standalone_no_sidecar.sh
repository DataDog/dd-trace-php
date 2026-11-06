#!/usr/bin/env bash
# Run inside a release image with strace: the standalone profiler must never
# start/connect to the sidecar, even with telemetry and thread mode requested.
set -euo pipefail

extension=$(realpath "${1:?usage: standalone_no_sidecar.sh /path/to/datadog-profiling.so}")
workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT

command -v strace >/dev/null
php -n -d "extension=$extension" -m | grep -Fx datadog-profiling >/dev/null

for mode in thread subprocess; do
DD_TRACE_SIDECAR_CONNECTION_MODE="$mode" \
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1 \
DD_APPSEC_ENABLED=true \
DD_PROFILING_ENABLED=1 \
DD_PROFILING_OUTPUT_PPROF="$workdir/profile-$mode" \
    strace -f -o "$workdir/trace-$mode" -e trace=execve,socket,bind,listen,connect \
    php -n -d "extension=$extension" -r '
        function ssi_profile_leaf($deadline) {
            $value = 1;
            while (microtime(true) < $deadline) {
                for ($i = 1; $i <= 10000; ++$i) {
                    $value = (($value * 33) ^ $i) & 0x7fffffff;
                }
            }
            return $value;
        }
        function ssi_profile_middle($deadline) { return ssi_profile_leaf($deadline); }
        function ssi_profile_root() { return ssi_profile_middle(microtime(true) + 2); }
        ssi_profile_root();
    ' >"$workdir/php-output-$mode" 2>&1 || { cat "$workdir/php-output-$mode"; exit 1; }

if grep -Eq 'socket\(|bind\(|listen\(|connect\(' "$workdir/trace-$mode" ||
   test "$(grep -c 'execve(' "$workdir/trace-$mode")" -ne 1; then
    echo "Standalone profiling attempted sidecar/network activity ($mode):" >&2
    grep -E 'socket\(|bind\(|listen\(|connect\(|execve\(' "$workdir/trace-$mode" >&2
    exit 1
fi
if grep -Ei 'warning|failed' "$workdir/php-output-$mode"; then
    echo "Standalone profiler produced a PHP warning or error ($mode)" >&2
    exit 1
fi
shopt -s nullglob
profiles=("$workdir"/profile-"$mode".*.zst)
if test "${#profiles[@]}" -eq 0 || ! test -s "${profiles[0]}"; then
    echo "Standalone profiler produced no profile ($mode)" >&2
    exit 1
fi
done
echo 'Standalone profiler produced profiles without attempting sidecar/network activity'
