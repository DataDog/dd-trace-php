#!/bin/bash
set -e

echo "=== Benchmark Regression Check ==="

# Check if files were changed that should trigger benchmarks
echo "Checking if benchmarks should run based on file changes..."
git fetch origin master:master 2>/dev/null || true
CHANGED_FILES=$(git diff --name-only HEAD master 2>/dev/null || echo "")

SHOULD_RUN_BENCHMARKS=false
if echo "$CHANGED_FILES" | grep -q -E "(ext/|src/|components/|components-rs/|zend_abstract_interface/|tests/Benchmarks/|benchmark/|tea/)"; then
  SHOULD_RUN_BENCHMARKS=true
  echo "File changes detected that should trigger benchmarks - will wait for artifacts"
else
  echo "No benchmark-triggering file changes detected - skipping regression check"
  exit 0
fi

# If we get here, benchmarks should run - wait for artifacts with retry logic
echo "Waiting for benchmark artifacts..."
for i in {1..30}; do  # Wait up to 5 minutes
  if curl -s --header "JOB-TOKEN: $CI_JOB_TOKEN" \
    "${CI_API_V4_URL}/projects/${CI_PROJECT_ID}/jobs/artifacts/${CI_COMMIT_REF_NAME}/download?job=benchmarks-tracer" \
    -o artifacts.zip && unzip -q artifacts.zip; then
    echo "Artifacts downloaded and extracted"
    break
  else
    if [ $i -eq 30 ]; then
      echo "ERROR: Expected benchmarks to run but no artifacts found after waiting"
      exit 1
    fi
    echo "Waiting for benchmarks to complete... ($i/30)"
    sleep 10
  fi
done

# Check reports and run regression analysis
if [ ! -d "reports/" ]; then
  echo "ERROR: Benchmarks should have run but no reports directory found"
  exit 1
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