#!/usr/bin/env bash

set -euo pipefail

readonly TRACER_BASE_SHA="736b0374d81c1f9a54c68da3658701efaa8468f5"
readonly TRACER_VERSION="1.26.0+dev.${TRACER_BASE_SHA}"
readonly SYSTEM_TESTS_CANDIDATE_SHA="fb49108f2e8c0f47b0576e9b24564dd86d28ec41"
readonly TEST_NODE_ID="tests/parametric/otel_env_vars/test_otel_blrp_max_queue_size.py::Test_OTEL_BLRP_MAX_QUEUE_SIZE::test_stable_value[64]"
readonly EXPECTED_JUNIT_TEST_NAME="tests.parametric.otel_env_vars.test_otel_blrp_max_queue_size.Test_OTEL_BLRP_MAX_QUEUE_SIZE.test_stable_value[64, parametric-php]"
readonly RUN_COUNT=100
readonly ARTIFACT_BASE_URL="https://s3.us-east-1.amazonaws.com/dd-trace-php-builds/${TRACER_VERSION/+/%2B}"

REPOSITORY_ROOT="$(pwd -P)"
readonly REPOSITORY_ROOT
readonly WORK_ROOT="${REPOSITORY_ROOT}/.blrp-diagnostic-work"
readonly OUTPUT_ROOT="${REPOSITORY_ROOT}/blrp-diagnostic-output"
readonly SYSTEM_TESTS_ROOT="${WORK_ROOT}/system-tests"

if [[ -e "${WORK_ROOT}" || -e "${OUTPUT_ROOT}" ]]; then
    echo "Diagnostic work or output directory already exists" >&2
    exit 2
fi

mkdir -p "${WORK_ROOT}/binaries" "${OUTPUT_ROOT}"

export TEST_LIBRARY=php
export PYTEST_XDIST_AUTO_NUM_WORKERS=8
export PIP_CACHE_DIR="${REPOSITORY_ROOT}/.cache/pip"
export APT_CACHE="${REPOSITORY_ROOT}/.cache/apt"
export DOCKER_DEFAULT_PLATFORM=linux/amd64
export BLRP_TELEMETRY_DIAGNOSTIC=1
export BLRP_EXPECTED_CONFIGURATION_NAME=OTEL_BLRP_MAX_QUEUE_SIZE
export BLRP_OBSERVATION_SECONDS=5
: "${GITHUB_RELEASES_MIRROR:?GITHUB_RELEASES_MIRROR must be set}"

mkdir -p "${PIP_CACHE_DIR}" "${APT_CACHE}/lists" "${APT_CACHE}/archives"
apt-get update -o "dir::state::lists=${APT_CACHE}/lists"
apt-get install -y --no-install-recommends \
    -o "dir::state::lists=${APT_CACHE}/lists" \
    -o "dir::cache::archives=${APT_CACHE}/archives" \
    ca-certificates curl git build-essential
mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
docker_codename=$(sed -n 's/^VERSION_CODENAME=//p' /etc/os-release)
docker_codename=${docker_codename//\"/}
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${docker_codename} stable" > /etc/apt/sources.list.d/docker.list
apt-get update -o "dir::state::lists=${APT_CACHE}/lists"
apt-get install -y --no-install-recommends \
    -o "dir::state::lists=${APT_CACHE}/lists" \
    -o "dir::cache::archives=${APT_CACHE}/archives" \
    docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
if [[ "$(git rev-parse --show-toplevel)" != "${REPOSITORY_ROOT}" ]]; then
    echo "Diagnostic must run from the repository root" >&2
    exit 2
fi

if ! git merge-base --is-ancestor "${TRACER_BASE_SHA}" HEAD; then
    echo "Diagnostic branch must descend from tracer ${TRACER_BASE_SHA}" >&2
    exit 2
fi

pip install -U pip virtualenv

if command -v docker >/dev/null 2>&1; then
    "${REPOSITORY_ROOT}/.gitlab/dockerhub-login.sh"
fi

curl -fL --retry 3 --retry-all-errors \
    "${ARTIFACT_BASE_URL}/datadog-setup.php" \
    -o "${WORK_ROOT}/binaries/datadog-setup.php"
curl -fL --retry 3 --retry-all-errors \
    "${ARTIFACT_BASE_URL}/dd-library-php-${TRACER_VERSION/+/%2B}-x86_64-linux-gnu.tar.gz" \
    -o "${WORK_ROOT}/binaries/dd-library-php-${TRACER_VERSION}-x86_64-linux-gnu.tar.gz"
sha256sum "${WORK_ROOT}/binaries/"* | tee "${OUTPUT_ROOT}/tracer-artifact-sha256.txt"

git clone https://github.com/DataDog/system-tests.git "${SYSTEM_TESTS_ROOT}"
git -C "${SYSTEM_TESTS_ROOT}" checkout --detach "${SYSTEM_TESTS_CANDIDATE_SHA}"
ACTUAL_SYSTEM_TESTS_SHA="$(git -C "${SYSTEM_TESTS_ROOT}" rev-parse HEAD)"
readonly ACTUAL_SYSTEM_TESTS_SHA
if [[ "${ACTUAL_SYSTEM_TESTS_SHA}" != "${SYSTEM_TESTS_CANDIDATE_SHA}" ]]; then
    echo "Unexpected system-tests revision: ${ACTUAL_SYSTEM_TESTS_SHA}" >&2
    exit 2
fi

{
    echo "tracer_base_sha=${TRACER_BASE_SHA}"
    echo "diagnostic_branch_sha=$(git rev-parse HEAD)"
    echo "system_tests_candidate_sha=${SYSTEM_TESTS_CANDIDATE_SHA}"
    echo "system_tests_actual_sha=${ACTUAL_SYSTEM_TESTS_SHA}"
    echo "system_tests_original_job_sha_status=bounded-candidate-not-verified"
    echo "test_node_id=${TEST_NODE_ID}"
    echo "run_count=${RUN_COUNT}"
} | tee "${OUTPUT_ROOT}/revisions.txt"

cp "${REPOSITORY_ROOT}/tooling/ci/blrp_diagnostic_plugin.py" "${SYSTEM_TESTS_ROOT}/blrp_diagnostic_plugin.py"
cp "${WORK_ROOT}/binaries/"* "${SYSTEM_TESTS_ROOT}/binaries/"

cd "${SYSTEM_TESTS_ROOT}"
export PYTHONPATH="${SYSTEM_TESTS_ROOT}${PYTHONPATH:+:${PYTHONPATH}}"
./build.sh -w php-fpm-7.3 php

pass_count=0
assertion_failure_count=0
infrastructure_failure_count=0
for run_number in $(seq 1 "${RUN_COUNT}"); do
    run_name=$(printf 'run-%03d' "${run_number}")
    run_output="${OUTPUT_ROOT}/${run_name}"
    mkdir -p "${run_output}"
    export BLRP_DIAGNOSTIC_OUTPUT="${run_output}/telemetry.jsonl"

    set +e
    ./run.sh PARAMETRIC "${TEST_NODE_ID}" -p blrp_diagnostic_plugin -s 2>&1 | tee "${run_output}/pytest.log"
    test_status=${PIPESTATUS[0]}
    set -e

    for artifact in reportJunit.xml tests.log _library_version.txt setup_properties.json; do
        if [[ -f "logs_parametric/${artifact}" ]]; then
            cp "logs_parametric/${artifact}" "${run_output}/${artifact}"
        fi
    done
    server_log="logs_parametric/outputs/Test_OTEL_BLRP_MAX_QUEUE_SIZE/test_stable_value[64]/server_log.log"
    if [[ -f "${server_log}" ]]; then
        cp "${server_log}" "${run_output}/server_log.log"
    fi

    set +e
    python3 - \
        "${test_status}" \
        "${run_output}/reportJunit.xml" \
        "${run_output}/telemetry.jsonl" \
        "${EXPECTED_JUNIT_TEST_NAME}" \
        "${BLRP_EXPECTED_CONFIGURATION_NAME}" \
        > "${run_output}/classification.txt" <<'PY'
import json
import sys
import xml.etree.ElementTree as ET


def classify() -> tuple[str, str]:
    pytest_status, report_path, telemetry_path, expected_name, expected_configuration = sys.argv[1:]
    cases = list(ET.parse(report_path).getroot().iter("testcase"))
    if len(cases) != 1:
        return "infrastructure-failure", f"expected-one-testcase-found-{len(cases)}"

    case = cases[0]
    if case.attrib.get("name") != expected_name:
        return "infrastructure-failure", "unexpected-testcase"

    records = []
    with open(telemetry_path, encoding="utf-8") as telemetry:
        for line in telemetry:
            if line.startswith("BLRP_DIAGNOSTIC "):
                records.append(json.loads(line.removeprefix("BLRP_DIAGNOSTIC ")))
    original_records = [record for record in records if record.get("phase") == "original-return"]
    completed_records = [record for record in records if record.get("phase") == "observation-complete"]
    if not original_records:
        return "infrastructure-failure", "missing-original-return-record"
    if not completed_records or completed_records[-1].get("elapsed_ms", 0) < 5000:
        return "infrastructure-failure", "incomplete-observation-window"
    if any(record.get("expected_configuration_name") != expected_configuration for record in original_records):
        return "infrastructure-failure", "unexpected-configuration-name"

    failures = list(case.findall("failure"))
    errors = list(case.findall("error"))
    skipped = list(case.findall("skipped"))
    if pytest_status == "0" and not failures and not errors and not skipped:
        return "pass", "test-passed"
    if pytest_status == "1" and len(failures) == 1 and not errors and not skipped:
        failure = failures[0]
        failure_text = " ".join(
            [failure.attrib.get("type", ""), failure.attrib.get("message", ""), failure.text or ""]
        )
        if "AssertionError" in failure_text:
            return "assertion-failure", "target-assertion-failed"
    return "infrastructure-failure", f"pytest-status-{pytest_status}"


try:
    classification, reason = classify()
except Exception as error:
    classification = "infrastructure-failure"
    reason = f"classification-error-{type(error).__name__}"
print(classification)
print(reason)
PY
    classification_status=$?
    set -e
    if [[ "${classification_status}" -ne 0 ]]; then
        printf '%s\n%s\n' "infrastructure-failure" "classification-process-exited-${classification_status}" > "${run_output}/classification.txt"
    fi

    classification=$(sed -n '1p' "${run_output}/classification.txt")
    reason=$(sed -n '2p' "${run_output}/classification.txt")
    case "${classification}" in
        pass)
            pass_count=$((pass_count + 1))
            ;;
        assertion-failure)
            assertion_failure_count=$((assertion_failure_count + 1))
            ;;
        *)
            classification="infrastructure-failure"
            infrastructure_failure_count=$((infrastructure_failure_count + 1))
            ;;
    esac
    printf '%s\tpytest_status=%s\tclassification=%s\treason=%s\n' \
        "${run_name}" "${test_status}" "${classification}" "${reason}" \
        | tee -a "${OUTPUT_ROOT}/run-status.tsv"

    if [[ "${run_number}" -eq 1 ]]; then
        parametric_runtime=$(docker run --rm --entrypoint php php-test-client -r 'echo json_encode(["role" => "parametric-test-client", "php_version" => PHP_VERSION, "ddtrace_version" => phpversion("ddtrace")]), PHP_EOL;')
        weblog_runtime=$(docker run --rm --entrypoint php system_tests/weblog -r 'echo json_encode(["role" => "selected-weblog", "php_version" => PHP_VERSION, "ddtrace_version" => phpversion("ddtrace")]), PHP_EOL;')
        {
            echo "parametric_client_image=php-test-client"
            docker image inspect php-test-client --format 'parametric_client_image_id={{.Id}} labels={{json .Config.Labels}}'
            echo "${parametric_runtime}"
            echo "selected_weblog_image=system_tests/weblog"
            docker image inspect system_tests/weblog --format 'selected_weblog_image_id={{.Id}} labels={{json .Config.Labels}}'
            echo "${weblog_runtime}"
        } | tee "${OUTPUT_ROOT}/runtime-images.txt"
        if ! python3 - "${parametric_runtime}" "${weblog_runtime}" "${TRACER_VERSION}" <<'PY'
import json
import sys


parametric = json.loads(sys.argv[1])
weblog = json.loads(sys.argv[2])
expected_tracer = sys.argv[3]
assert parametric["php_version"].startswith("8.2."), parametric["php_version"]
assert weblog["php_version"].startswith("7.3."), weblog["php_version"]
assert parametric["ddtrace_version"] == expected_tracer, parametric["ddtrace_version"]
assert weblog["ddtrace_version"] == expected_tracer, weblog["ddtrace_version"]
PY
        then
            echo "runtime-hard-check-failed" | tee "${OUTPUT_ROOT}/setup-failure.txt"
            exit 2
        fi
        if [[ -f logs_parametric/outputs/docker_build_log.log ]]; then
            cp logs_parametric/outputs/docker_build_log.log "${OUTPUT_ROOT}/parametric-client-build.log"
        fi
    fi
done

{
    echo "passes=${pass_count}"
    echo "assertion_failures=${assertion_failure_count}"
    echo "infrastructure_failures=${infrastructure_failure_count}"
    echo "total=${RUN_COUNT}"
} | tee "${OUTPUT_ROOT}/summary.txt"

if [[ "${assertion_failure_count}" -gt 0 || "${infrastructure_failure_count}" -gt 0 ]]; then
    exit 1
fi
