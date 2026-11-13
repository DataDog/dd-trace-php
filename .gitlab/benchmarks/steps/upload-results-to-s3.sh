#!/usr/bin/env bash

PROJECT="${UPSTREAM_PROJECT_NAME:-$CI_PROJECT_NAME}"
BRANCH="${UPSTREAM_BRANCH:-$CI_COMMIT_REF_NAME}"

# BP_EXTERNAL_S3_URL overrides the default destination prefix (must stay within
# relenv-benchmarking-data) so an external orchestrator can collect results
# under a prefix it controls.
S3_URL="${BP_EXTERNAL_S3_URL:-s3://relenv-benchmarking-data/${PROJECT}/${BRANCH}/${CI_JOB_ID}/}"
[[ "$S3_URL" == s3://relenv-benchmarking-data/* ]] || { echo "BP_EXTERNAL_S3_URL must stay within relenv-benchmarking-data" >&2; exit 1; }

aws s3 cp --recursive --acl bucket-owner-full-control "${ARTIFACTS_DIR}/" "$S3_URL"
