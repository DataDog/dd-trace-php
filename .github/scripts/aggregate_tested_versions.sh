#!/usr/bin/env bash
set -euo pipefail

source ./.gitlab/download-circleci_artifact.sh

PROJECT_SLUG="gh/DataDog/dd-trace-php"
WORKFLOW_NAME="build"
ARTIFACT_NAME="tested_versions.json"
ARTIFACT_PATTERN="tested_versions.json"
OUTPUT_FILE="aggregated_tested_versions.json"

RUNTIMES=("7.0" "7.1" "7.2" "7.3" "7.4" "8.0" "8.1" "8.2" "8.3")
JOB_NAMES=()
for RUNTIME in "${RUNTIMES[@]}"; do
    JOB_NAMES+=("integration-snapshots-test_integrations-${RUNTIME}")
    JOB_NAMES+=("integration-snapshots-test_web-${RUNTIME}")
done

TEMP_DIR=$(mktemp -d)
cleanup() {
    rm -rf "${TEMP_DIR}"
}
trap cleanup EXIT

for JOB_NAME in "${JOB_NAMES[@]}"; do
    echo "Processing job: ${JOB_NAME}"
    download_circleci_artifact "${PROJECT_SLUG}" "${WORKFLOW_NAME}" "${JOB_NAME}" "${ARTIFACT_PATTERN}" "${TEMP_DIR}/${JOB_NAME}.json" 0
done

echo "Aggregating JSON files..."
jq -s 'reduce .[] as $item ({};
  reduce (keys_unsorted | .[]) as $key ($item;
    $item[$key] += ($item[$key] // [] | unique)
  )
)' "${TEMP_DIR}"/*.json > "${OUTPUT_FILE}"

echo "Aggregation complete. Output written to ${OUTPUT_FILE}"
