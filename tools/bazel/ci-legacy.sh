#!/usr/bin/env bash
# The existing release build, limited to PHP 8.5 glibc NTS/debug/ZTS.
set -euo pipefail
shopt -s expand_aliases
source "${BASH_ENV}"
if [[ -d /opt/rh/devtoolset-7 ]]; then
    set +u
    source scl_source enable devtoolset-7
    set -u
fi

# Run the production compilation stages concurrently, as in the release DAG.
# Wait for both and preserve either failure (a bare `wait` loses failures).
bash .gitlab/build-tracing.sh "" "1" &
tracing_pid=$!
bash .gitlab/build-sidecar.sh "" &
sidecar_pid=$!
status=0
wait "$tracing_pid" || status=$?
wait "$sidecar_pid" || status=$?
[[ "$status" == 0 ]] || exit "$status"

# Production takes these platform-wide files from its PHP 7.0 shard. This
# representative shard produces the same files in its own extension build.
cp tmp/build_extension/ddtrace-fat.ldflags "ddtrace_$(uname -m)-fat.ldflags"
cp tmp/build_extension/ddtrace-fat.sym "ddtrace_$(uname -m)-fat.sym"
bash .gitlab/link-tracing-extension.sh ""

for profile in '' '-debug' '-zts'; do
    switch-php "8.5${profile}"
    extension="${PWD}/extensions_$(uname -m)/ddtrace-20250925${profile}.so"
    test -s "$extension"
    php -n -d "extension=${extension}" --ri ddtrace
done
