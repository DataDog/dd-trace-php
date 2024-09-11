#!/usr/bin/env bash
set -euo pipefail

echoerr() {
    echo "${1}" >&2
}

call_api() {
    URL="${1}"
    echoerr "[API] ${URL}"
    curl --retry 5 --fail --silent --show-error "${URL}" -H "Circle-Token: ${CIRCLECI_TOKEN}" || exit 1
}

json_extract() {
    JSON="${1}"
    QUERY="${2}"
    echo "${JSON}" | jq -r "${QUERY}"
}

download_circleci_artifact() {
    SLUG=$1                 # "gh/DataDog/dd-trace-php"
    WORKFLOW_NAME=$2        # "build_packages"
    JOB_NAME=$3             # "Compile Loader Linux x86_64"
    ARTIFACT_PATTERN=$4     # "loader/modules/dd_library_loader.so"
    ARTIFACT_NAME=$5        # "dd_library_loader-x86_64-linux-gnu.so"

    # Circle CI workflow is not triggered by tags,
    # So we fallback to the release branch (eg. "ddtrace-1.3.0")
    BRANCH=${CI_COMMIT_BRANCH:-"ddtrace-${CI_COMMIT_TAG}"}  # Set by Gilab CI
    COMMIT_SHA=${CI_COMMIT_SHA}                             # Set by Gilab CI

    PIPELINES=$(call_api "https://circleci.com/api/v2/project/${SLUG}/pipeline?branch=${BRANCH}")

    # Ensure it's the same GIT commit
    REVISION=$(json_extract "${PIPELINES}" ".items[0].vcs.revision")
    if [[ "${COMMIT_SHA}" != "${REVISION}" ]]; then
        echo "The git commit hash (${CI_COMMIT_SHA}) does not match the one in the CircleCI pipeline (${REVISION})"
        exit 1
    fi

    PIPELINE_ID=$(json_extract "${PIPELINES}" ".items[0].id")
    PIPELINE_NUMBER=$(json_extract "${PIPELINES}" ".items[0].number")

    WORKFLOWS=$(call_api "https://circleci.com/api/v2/pipeline/${PIPELINE_ID}/workflow")
    WORKFLOW_ID=$(json_extract "${WORKFLOWS}" "first(.items[] | select(.name == \"${WORKFLOW_NAME}\")) | .id")

    JOB_NUMBER=""
    for i in {0..120}; do
        JOBS=$(call_api "https://circleci.com/api/v2/workflow/${WORKFLOW_ID}/job")
        JOB=$(json_extract "${JOBS}" ".items[] | select(.name == \"${JOB_NAME}\")")

        JOB_STATUS=$(json_extract "${JOB}" ".status")
        if [[ "${JOB_STATUS}" == "running" || "${JOB_STATUS}" == "blocked" || "${JOB_STATUS}" == "not_running" ]]; then
            echo "Job is still waiting or running. Will try again in 30 seconds..."
            sleep 30s
            continue
        fi

        if [[ "${JOB_STATUS}" != "success" ]]; then
            printf "CircleCI job is not successful:\n ${JOB}\n"
            exit 1
        fi

        JOB_NUMBER=$(json_extract "${JOB}" ".job_number")
        break
    done

    if [[ "${JOB_NUMBER}" == "" ]]; then
        echo "Timeout. Is CircleCI job still running?"
        exit 1
    fi

    ARTIFACTS=$(call_api "https://circleci.com/api/v2/project/${SLUG}/${JOB_NUMBER}/artifacts")
    QUERY=".items[] | select(.path | test(\"$ARTIFACT_PATTERN\"))"
    ARTIFACT_URL=$(json_extract "${ARTIFACTS}" ".items[] | select(.path | test(\"$ARTIFACT_PATTERN\")) | .url")

    if [ -z "${ARTIFACT_URL}" ]; then
        echo "Oooops, I did not found any artifact that satisfy this pattern: ${ARTIFACT_PATTERN}. Here is the list:"
        echo $(json_extract "${ARTIFACTS}" ".items[] | .path")
        exit 1
    fi

    echo "Artifact URL: ${ARTIFACT_URL}"
    echo "Artifact name: ${ARTIFACT_NAME}"
    echo "Downloading artifact..."

    curl --silent -L "${ARTIFACT_URL}" --output "${ARTIFACT_NAME}"
}

if [[ -f ".env" ]]; then
    source .env
fi
