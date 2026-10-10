#!/usr/bin/env bash
set -uo pipefail

fail() {
    local stage=$1
    shift
    echo "FAIL [${stage}]: $*" >&2
    if [[ -n ${work_dir:-} && -d ${work_dir:-} ]]; then
        echo "Diagnostic files: ${work_dir}" >&2
        [[ -f ${work_dir}/php.stdout ]] && { echo "--- PHP stdout ---" >&2; cat "${work_dir}/php.stdout" >&2; }
        [[ -f ${work_dir}/php.stderr ]] && { echo "--- PHP stderr ---" >&2; cat "${work_dir}/php.stderr" >&2; }
        if compgen -G "${work_dir}/profile.pprof.*" >/dev/null; then
            echo "--- Profile files ---" >&2
            ls -l "${work_dir}"/profile.pprof.* >&2
        fi
    fi
    exit 1
}

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
loader_dir=$(cd "${script_dir}/.." && pwd)
fixture="${loader_dir}/tests/functional/fixtures/ssi_profile_stack.php"
verifier="${script_dir}/verify_pprof.py"
php_bin=${PHP_BIN:-php}
loader_so=${DD_LOADER_SO:-${loader_dir}/modules/dd_library_loader.so}

[[ -n ${DD_LOADER_PACKAGE_PATH:-} ]] || fail setup 'DD_LOADER_PACKAGE_PATH is not set'
[[ -d ${DD_LOADER_PACKAGE_PATH} ]] || fail setup "SSI package directory does not exist: ${DD_LOADER_PACKAGE_PATH}"
[[ -f ${loader_so} ]] || fail setup "loader shared object does not exist: ${loader_so}"
[[ -f ${fixture} ]] || fail setup "workload fixture does not exist: ${fixture}"
[[ -f ${verifier} ]] || fail setup "profile verifier does not exist: ${verifier}"
command -v "${php_bin}" >/dev/null 2>&1 || fail setup "PHP executable was not found: ${php_bin}"
command -v python3 >/dev/null 2>&1 || fail setup 'python3 is required to validate pprof structure'

if [[ -n ${SSI_PROFILE_ARTIFACT_DIR:-} ]]; then
    work_dir=${SSI_PROFILE_ARTIFACT_DIR}
    rm -rf "${work_dir}"
    mkdir -p "${work_dir}" || fail setup "cannot create artifact directory: ${work_dir}"
else
    work_dir=$(mktemp -d -t loader-ssi-profile.XXXXXX) || fail setup 'cannot create temporary directory'
fi

profile_prefix="${work_dir}/profile.pprof"

set +e
env \
    DD_LOADER_PACKAGE_PATH="${DD_LOADER_PACKAGE_PATH}" \
    DD_PROFILING_ENABLED=1 \
    DD_PROFILING_OUTPUT_PPROF="${profile_prefix}" \
    DD_PROFILING_LOG_LEVEL=off \
    DD_TRACE_ENABLED=0 \
    DD_APPSEC_ENABLED=0 \
    DD_REMOTE_CONFIG_ENABLED=0 \
    DD_INSTRUMENTATION_TELEMETRY_ENABLED=0 \
    SSI_PROFILE_DURATION_SECONDS="${SSI_PROFILE_DURATION_SECONDS:-3}" \
    "${php_bin}" -n -d "zend_extension=${loader_so}" "${fixture}" \
    >"${work_dir}/php.stdout" 2>"${work_dir}/php.stderr"
php_status=$?
set -e

[[ ${php_status} -eq 0 ]] || fail workload "PHP exited with status ${php_status}"
grep -q '^SSI profile workload completed; php=' "${work_dir}/php.stdout" \
    || fail workload 'PHP exited successfully but did not print its completion marker'

shopt -s nullglob
profiles=("${profile_prefix}".*.zst)
shopt -u nullglob
[[ ${#profiles[@]} -gt 0 ]] || fail output 'profiler produced no .zst pprof file at process shutdown'
[[ ${#profiles[@]} -eq 1 ]] || fail output "expected one profile, found ${#profiles[@]}"
[[ -s ${profiles[0]} ]] || fail output "profile is empty: ${profiles[0]}"

if ! python3 "${verifier}" "${profiles[0]}" >"${work_dir}/validation.stdout" 2>"${work_dir}/validation.stderr"; then
    cat "${work_dir}/validation.stderr" >&2
    fail profile 'pprof did not contain a usable SSI workload stack'
fi

cat "${work_dir}/php.stdout"
cat "${work_dir}/validation.stdout"

if [[ -z ${SSI_PROFILE_ARTIFACT_DIR:-} ]]; then
    rm -rf "${work_dir}"
fi
