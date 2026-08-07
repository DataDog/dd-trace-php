#!/usr/bin/env bash
#
# Regression test for SSI loader crashes on Apache graceful reload.
#
# On "apachectl graceful" Apache re-runs php_module_startup() in the very same process, after
# having dlclose()'d and re-dlopen()'d dd_library_loader.so (which lands at a *different* address),
# while the previously injected ddtrace.so/datadog-profiling.so usually stay resident (glibc refuses
# to unmap libraries that started threads). If the loader leaves its in-place patches on the
# injected module's static zend_module_entry behind, the second startup re-captures those already
# patched values -- pointers into the now unmapped previous loader image -- installs and calls them,
# and the Apache parent dies with SIGSEGV (and the userland DDTrace\* functions disappear, because
# the "original" function table was captured as NULL).
#
# This test therefore asserts, after each of several consecutive graceful reloads:
#   1. the Apache *parent* pid is unchanged and still alive (apachectl graceful silently cold-starts
#      a fresh httpd when the parent died, so the response alone proves nothing),
#   2. requests still report ddtrace loaded, with the same phpversion('ddtrace'),
#   3. function_exists('DDTrace\trace_function') is still true,
#   4. the profiler is still loaded (it takes part in the resident-image situation).
#
# Required: DD_LOADER_PACKAGE_PATH pointing at an extracted dd-library-php-ssi package.
# Optional: DD_LOADER_SO (defaults to ./modules/dd_library_loader.so), APACHE_RELOADS (default 3),
#           TEST_DOCROOT (default /var/www/html), APACHE_LISTEN_ADDR (default 127.0.0.1),
#           APACHE_LISTEN_PORT (default 80).

set -uo pipefail

RELOADS="${APACHE_RELOADS:-3}"
DOCROOT="${TEST_DOCROOT:-/var/www/html}"
# Bind explicitly instead of taking the image's default (0.0.0.0:80): anything else listening in
# the container makes Apache fail to bind with AH00072 rather than run the test.
LISTEN_ADDR="${APACHE_LISTEN_ADDR:-127.0.0.1}"
LISTEN_PORT="${APACHE_LISTEN_PORT:-80}"
LOADER_SO="${DD_LOADER_SO:-${PWD}/modules/dd_library_loader.so}"
TEST_SCRIPT_NAME="dd_loader_apache_reload.php"

if [[ -z "${DD_LOADER_PACKAGE_PATH:-}" ]]; then
    echo "FAILURE: env var 'DD_LOADER_PACKAGE_PATH' is required"
    exit 1
fi
if [[ ! -f "${LOADER_SO}" ]]; then
    echo "FAILURE: loader not found at '${LOADER_SO}' (set DD_LOADER_SO)"
    exit 1
fi

SUDO=""
if [[ "$(id -u)" != "0" ]]; then
    SUDO="sudo"
fi

for tool in apachectl curl; do
    if ! command -v "${tool}" >/dev/null 2>&1; then
        echo "FAILURE: '${tool}' is required but not installed"
        exit 1
    fi
done

# Not "php -n": -n disables the scan dir, and mod_php uses the very same (compile time) one.
INI_SCAN_DIR="$(php -i | sed -n 's/^Scan this dir for additional .ini files => //p')"
if [[ -z "${INI_SCAN_DIR}" || "${INI_SCAN_DIR}" == "(none)" ]]; then
    echo "FAILURE: this PHP build has no additional .ini scan directory"
    exit 1
fi
INI_FILE="${INI_SCAN_DIR}/98-dd-loader-reload-test.ini"

# Apache reads the env of the process that starts it; mod_php then hands it to the loader.
export DD_INJECTION_ENABLED=tracing
export DD_PROFILING_ENABLED=1
export DD_TRACE_AGENT_URL="${DD_TRACE_AGENT_URL:-http://127.0.0.1:18126}"
export DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
export DD_TRACE_STARTUP_LOGS=0

# shellcheck disable=SC1091
. /etc/apache2/envvars
$SUDO mkdir -p "${APACHE_RUN_DIR}" "${APACHE_LOCK_DIR}" "${APACHE_LOG_DIR}"

PORTS_CONF="/etc/apache2/ports.conf"
DEFAULT_SITE="/etc/apache2/sites-available/000-default.conf"
BACKUP_SUFFIX=".dd-reload-test.bak"

cleanup() {
    local rc=$?
    $SUDO -E apachectl stop >/dev/null 2>&1
    $SUDO rm -f "${INI_FILE}" "${DOCROOT}/${TEST_SCRIPT_NAME}"
    for conf in "${PORTS_CONF}" "${DEFAULT_SITE}"; do
        if [[ -f "${conf}${BACKUP_SUFFIX}" ]]; then
            $SUDO mv "${conf}${BACKUP_SUFFIX}" "${conf}"
        fi
    done
    if [[ ${rc} -eq 0 ]]; then
        printf "\nSUCCESS\n"
    else
        printf "\nFAILURE\n"
    fi
    exit ${rc}
}
trap cleanup EXIT

echo "PHP version:"
php -n -v
echo "Loader: ${LOADER_SO}"
echo "Package: ${DD_LOADER_PACKAGE_PATH}"
echo "INI scan dir: ${INI_SCAN_DIR}"

echo "zend_extension=${LOADER_SO}" | $SUDO tee "${INI_FILE}" >/dev/null

$SUDO mkdir -p "${DOCROOT}"
cat <<'PHP' | $SUDO tee "${DOCROOT}/${TEST_SCRIPT_NAME}" >/dev/null
<?php
printf(
    "ddtrace=%s version=%s trace_function=%s profiling=%s\n",
    extension_loaded('ddtrace') ? 'yes' : 'no',
    phpversion('ddtrace') ?: '-',
    function_exists('DDTrace\\trace_function') ? 'yes' : 'no',
    extension_loaded('datadog-profiling') ? 'yes' : 'no'
);
PHP

# Fetch the test page, retrying while Apache is (re)starting.
request() {
    local out=""
    for _ in $(seq 1 40); do
        out="$(curl -sf -m 5 "http://${LISTEN_ADDR}:${LISTEN_PORT}/${TEST_SCRIPT_NAME}" 2>/dev/null)"
        if [[ -n "${out}" ]]; then
            printf '%s' "${out}"
            return 0
        fi
        sleep 0.5
    done
    return 1
}

parent_pid() {
    [[ -f "${APACHE_PID_FILE}" ]] && cat "${APACHE_PID_FILE}" 2>/dev/null
}

# ServerName goes in here too, so the log doesn't start with AH00558.
$SUDO cp "${PORTS_CONF}" "${PORTS_CONF}${BACKUP_SUFFIX}"
printf 'Listen %s:%s\nServerName %s\n' "${LISTEN_ADDR}" "${LISTEN_PORT}" "${LISTEN_ADDR}" \
    | $SUDO tee "${PORTS_CONF}" >/dev/null
if [[ -f "${DEFAULT_SITE}" && "${LISTEN_PORT}" != "80" ]]; then
    $SUDO cp "${DEFAULT_SITE}" "${DEFAULT_SITE}${BACKUP_SUFFIX}"
    $SUDO sed -i "s/\*:80/*:${LISTEN_PORT}/" "${DEFAULT_SITE}"
fi

echo
echo "Starting Apache on ${LISTEN_ADDR}:${LISTEN_PORT}"
# An httpd left behind by an earlier (killed) run would hold the port.
$SUDO -E apachectl stop >/dev/null 2>&1 || true
if ! $SUDO -E apachectl start; then
    echo "ERROR: could not start Apache"
    exit 1
fi

BASELINE="$(request)" || { echo "ERROR: no response from Apache after start"; exit 1; }
START_PID="$(parent_pid)"
echo "Initial response (parent pid ${START_PID:-none}): ${BASELINE}"

if [[ -z "${START_PID}" ]]; then
    echo "ERROR: Apache pid file '${APACHE_PID_FILE}' is missing; cannot detect a parent crash"
    exit 1
fi

# The profiler is only injected on PHP >= 7.1 (see DECLARE_INJECTED_EXT in dd_library_loader.c).
EXPECT_PROFILING=0
if [[ "$(php -r 'echo PHP_VERSION_ID;')" -ge 70100 ]]; then
    EXPECT_PROFILING=1
fi

assert_response() {
    local label="$1" resp="$2" failed=0
    [[ "${resp}" == *"ddtrace=yes"* ]] || { echo "ERROR (${label}): ddtrace is not loaded"; failed=1; }
    [[ "${resp}" == *"trace_function=yes"* ]] || { echo "ERROR (${label}): DDTrace\\trace_function() is gone"; failed=1; }
    if [[ ${EXPECT_PROFILING} -eq 1 && "${resp}" != *"profiling=yes"* ]]; then
        echo "ERROR (${label}): the profiler is not loaded"
        failed=1
    fi
    if [[ "${resp}" != "${BASELINE}" ]]; then
        echo "ERROR (${label}): response changed"
        echo "  expected: ${BASELINE}"
        echo "  actual:   ${resp}"
        failed=1
    fi
    return ${failed}
}

if ! assert_response "startup" "${BASELINE}"; then
    echo "ERROR: the initial request already failed the expectations; nothing to test"
    exit 1
fi

FAILED=0
for i in $(seq 1 "${RELOADS}"); do
    echo
    echo "=== graceful reload ${i}/${RELOADS} (parent pid ${START_PID})"
    $SUDO -E apachectl graceful || { echo "ERROR: 'apachectl graceful' failed"; FAILED=1; break; }
    # Give the parent a chance to die, and the new generation to come up.
    sleep 2

    NEW_PID="$(parent_pid)"
    # Apache runs as root, so signal it through sudo (kill -0 would give EPERM otherwise).
    if [[ -z "${NEW_PID}" ]] || ! $SUDO kill -0 "${NEW_PID}" 2>/dev/null; then
        echo "ERROR: the Apache parent is gone after reload ${i} (pid file: '${NEW_PID:-missing}')"
        FAILED=1
        break
    fi
    if [[ "${NEW_PID}" != "${START_PID}" ]]; then
        echo "ERROR: the Apache parent pid changed after reload ${i}: ${START_PID} -> ${NEW_PID}."
        echo "       It crashed and was cold-started again, which is exactly the bug under test."
        FAILED=1
        break
    fi
    echo "  parent pid ${NEW_PID} still alive"

    RESP="$(request)" || { echo "ERROR: no response after reload ${i}"; FAILED=1; break; }
    echo "  response: ${RESP}"
    assert_response "reload ${i}" "${RESP}" || FAILED=1
done

echo
if [[ ${FAILED} -ne 0 ]]; then
    echo "Apache error log tail:"
    $SUDO tail -n 50 "${APACHE_LOG_DIR}/error.log" 2>/dev/null || true
    exit 1
fi

echo "Apache survived ${RELOADS} graceful reloads with the loader injected"
