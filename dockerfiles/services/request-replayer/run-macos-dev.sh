#!/usr/bin/env bash
# Run request-replayer natively on macOS for local development.
#
# There's no Docker service network on macOS the way CI's Linux/Windows jobs get it
# (see the "macos test_c" job in .gitlab/generate-tracer.php for the CI equivalent of
# this script), so this runs request-replayer as a plain background process on
# loopback instead, with the "request-replayer" hostname aliased via /etc/hosts.
#
# Usage:
#   sudo dockerfiles/services/request-replayer/run-macos-dev.sh
#
# Then, in another terminal:
#   export PATH="/path/to/your/php-build/bin:${PATH}"
#   DATADOG_HAVE_DEV_ENV=1 make test_c
#
# Re-run this script any time -- it always starts from a clean slate (kills any
# previous instance and wipes its on-disk state) rather than accumulating state
# across runs, which is the #1 source of flaky "wait for replay timeout" /
# "request log does not exist" failures when reusing a long-lived local instance:
# request-replayer stores all its per-test state as plain files under
# sys_get_temp_dir()/token-<test-token>/ (see src/index.php), and CI never hits this
# because it gets a brand-new VM (and therefore an empty temp dir) on every run.

set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Must run as root (needs to bind port 80 and edit /etc/hosts): sudo $0" >&2
    exit 1
fi

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/src" && pwd)"
REQUEST_REPLAYER_PHP="$(brew --prefix php)/bin/php"

if [[ ! -x "${REQUEST_REPLAYER_PHP}" ]]; then
    echo "brew's php not found -- run: brew install php" >&2
    exit 1
fi

if ! grep -qx '127\.0\.0\.1[[:space:]]request-replayer' /etc/hosts; then
    # Remove any stale entry pointing elsewhere (e.g. a leftover loopback alias from
    # an earlier manual setup) rather than assuming an existing entry is correct.
    sed -i '' '/[[:space:]]request-replayer$/d' /etc/hosts
    echo "127.0.0.1 request-replayer" >> /etc/hosts
    echo "Added/fixed 'request-replayer' in /etc/hosts"
fi

if [[ ! -d "${SRC_DIR}/vendor" ]]; then
    echo "Installing composer dependencies..."
    "${REQUEST_REPLAYER_PHP}" -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    "${REQUEST_REPLAYER_PHP}" /tmp/composer-setup.php --install-dir=/tmp --filename=composer.phar
    (cd "${SRC_DIR}" && "${REQUEST_REPLAYER_PHP}" /tmp/composer.phar install --no-interaction)
fi

# Kill any previous instance -- by port, not by a remembered PID, so this is robust
# even if a previous run of this script (or a manual invocation) was left dangling.
EXISTING_PIDS="$(lsof -ti :80 -sTCP:LISTEN 2>/dev/null | tr '\n' ' ' || true)"
if [[ -n "${EXISTING_PIDS// /}" ]]; then
    echo "Killing existing listener(s) on :80 (pid ${EXISTING_PIDS})"
    kill -9 ${EXISTING_PIDS}
    sleep 1
fi

# Wipe on-disk state (see the comment above) so every start is a clean slate,
# matching what CI gets for free from a fresh VM.
TEMP_DIR="$("${REQUEST_REPLAYER_PHP}" -r 'echo sys_get_temp_dir();')"
rm -rf "${TEMP_DIR}"/token-* "${TEMP_DIR}"/dump.json "${TEMP_DIR}"/response.json \
    "${TEMP_DIR}"/requests-log.txt "${TEMP_DIR}"/rc_configs.json "${TEMP_DIR}"/rc_requests.json \
    "${TEMP_DIR}"/metrics.json "${TEMP_DIR}"/metrics-log.txt "${TEMP_DIR}"/stats.json \
    "${TEMP_DIR}"/agent-info.txt "${TEMP_DIR}"/.state.lock "${TEMP_DIR}"/metrics-server.*

LOG_FILE="/tmp/request-replayer.log"
cd "${SRC_DIR}"
PHP_CLI_SERVER_WORKERS=16 "${REQUEST_REPLAYER_PHP}" -S 127.0.0.1:80 index.php > "${LOG_FILE}" 2>&1 &
disown
sleep 1

if curl -s -o /dev/null -w '%{http_code}' http://request-replayer:80/ | grep -q 200; then
    echo "request-replayer is up: http://request-replayer:80 (log: ${LOG_FILE})"
else
    echo "request-replayer did not come up -- check ${LOG_FILE}" >&2
    exit 1
fi
