#!/bin/bash
set -e

echo "=== Benchmark Regression Check ==="

# Try to download benchmark artifacts (ignore failure if job didn't run)
echo "Attempting to download benchmark artifacts..."
if curl -s --header "JOB-TOKEN: $CI_JOB_TOKEN" \
  "${CI_API_V4_URL}/projects/${CI_PROJECT_ID}/jobs/artifacts/${CI_COMMIT_REF_NAME}/download?job=benchmarks-tracer" \
  -o artifacts.zip && unzip -q artifacts.zip; then
  echo "Artifacts downloaded and extracted"
else
  echo "No artifacts found (benchmarks likely didn't run)."
fi

# Check reports directory and apply regression logic
if [ ! -d "reports/" ]; then
  echo "No reports directory found - benchmarks were not needed for this PR. Skipping regression check."
  exit 0
elif [ -z "$(ls -A reports/ 2>/dev/null)" ]; then
  echo "ERROR: Reports directory exists but is empty - benchmarks job had issues"
  exit 1
else
  echo "Reports directory found with content - running regression check"
  export ARTIFACTS_DIR="$(pwd)/reports/"
  
  # Setup git access for private repos
  if [[ -n "$CI_JOB_TOKEN" ]]; then
    git config --global url."https://gitlab-ci-token:${CI_JOB_TOKEN}@gitlab.ddbuild.io/DataDog/".insteadOf "https://github.com/DataDog/"
  fi
  
  # Clone benchmarking platform and run regression check
  git clone --branch dd-trace-php https://github.com/DataDog/benchmarking-platform /platform
  export PATH="$PATH:/platform/steps"
  bp-runner /platform/bp-runner.fail-on-regression.yml --debug
fi 