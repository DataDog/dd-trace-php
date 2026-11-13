#!/usr/bin/env bash

# Temporarily enable uploading from inside pr commenter script, till dd-trace-py repo has an update
$(dirname "$0")/upload-results-to-benchmarking-api.sh || :

cat "$ARTIFACTS_DIR/comparison-baseline-vs-candidate.md" | pr-commenter --for-repo="$UPSTREAM_PROJECT_NAME" --for-pr="$UPSTREAM_BRANCH" --header="Benchmarks [ $SCENARIO ]" --on-duplicate=replace
