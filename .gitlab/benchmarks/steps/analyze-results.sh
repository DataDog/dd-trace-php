#!/usr/bin/env bash

set -ex

# Benchmark analyzer config

source $(dirname "$0")/config-benchmark-analyzer.sh

CPU_MODEL=$(cat "$ARTIFACTS_DIR/lscpu.txt" | grep -Eo 'Model name: .*' | sed 's/Model name://' | awk '{$1=$1;print}')
CI_JOB_DATE=$(date +%s)

if [ -z "$CPU_MODEL" ]; then
  echo "FAIL! Failed to extract CPU_MODEL from lscpu.txt"
  exit 1
fi

KERNEL_VERSION=$(uname -a || echo "Unknown")

# -- Analyze candidate branch results

CANDIDATE_BRANCH=$UPSTREAM_BRANCH
CANDIDATE_SRC="/app/candidate/"

# Get commit SHA for candidate branch
cd "$CANDIDATE_SRC"
CANDIDATE_COMMIT_SHA=$(git rev-parse HEAD)
CANDIDATE_COMMIT_DATE=$(git show -s --format=%ct $CANDIDATE_COMMIT_SHA)

if [ "$SCENARIO" = "profiler" ]; then
  benchmark_analyzer convert \
    --extra-params="{\
    \"baseline_or_candidate\":\"candidate\", \
    \"cpu_model\":\"$CPU_MODEL\", \
    \"kernel_version\":\"$KERNEL_VERSION\", \
    \"ci_job_date\":\"$CI_JOB_DATE\", \
    \"ci_job_id\":\"$CI_JOB_ID\", \
    \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
    \"git_commit_sha\":\"$CANDIDATE_COMMIT_SHA\", \
    \"git_commit_date\":\"$CANDIDATE_COMMIT_DATE\", \
    \"git_branch\":\"$CANDIDATE_BRANCH\"\
    }" \
    --framework=Sirun \
    --outpath="$ARTIFACTS_DIR/candidate_timeline_memory.converted.json" \
    "$ARTIFACTS_DIR/candidate/sirun_timeline.ndjson"

  benchmark_analyzer convert \
    --extra-params="{\
    \"baseline_or_candidate\":\"candidate\", \
    \"cpu_model\":\"$CPU_MODEL\", \
    \"kernel_version\":\"$KERNEL_VERSION\", \
    \"ci_job_date\":\"$CI_JOB_DATE\", \
    \"ci_job_id\":\"$CI_JOB_ID\", \
    \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
    \"git_commit_sha\":\"$CANDIDATE_COMMIT_SHA\", \
    \"git_commit_date\":\"$CANDIDATE_COMMIT_DATE\", \
    \"git_branch\":\"$CANDIDATE_BRANCH\"\
    }" \
    --framework=Sirun \
    --outpath="$ARTIFACTS_DIR/candidate_exceptions.converted.json" \
    "$ARTIFACTS_DIR/candidate/sirun_exceptions.ndjson"

  benchmark_analyzer analyze \
    --format=md \
    --outpath="$ARTIFACTS_DIR/candidate-analysis.md" \
    "$ARTIFACTS_DIR/candidate_timeline_memory.converted.json" \
    "$ARTIFACTS_DIR/candidate_exceptions.converted.json"
elif [ "$SCENARIO" = "tracer" ]; then
  # Analyze Tracing Benchmarks
  benchmark_analyzer convert \
    --extra-params="{\
    \"baseline_or_candidate\":\"candidate\", \
    \"cpu_model\":\"$CPU_MODEL\", \
    \"kernel_version\":\"$KERNEL_VERSION\", \
    \"ci_job_date\":\"$CI_JOB_DATE\", \
    \"ci_job_id\":\"$CI_JOB_ID\", \
    \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
    \"git_commit_sha\":\"$CANDIDATE_COMMIT_SHA\", \
    \"git_commit_date\":\"$CANDIDATE_COMMIT_DATE\", \
    \"git_branch\":\"$CANDIDATE_BRANCH\"\
    }" \
    --framework=PHPBench \
    --outpath="$ARTIFACTS_DIR/candidate_${SCENARIO}_bench.converted.json" \
    "$ARTIFACTS_DIR/candidate/${SCENARIO}-bench-results.csv"

  benchmark_analyzer convert \
    --extra-params="{\
    \"baseline_or_candidate\":\"candidate\", \
    \"cpu_model\":\"$CPU_MODEL\", \
    \"kernel_version\":\"$KERNEL_VERSION\", \
    \"ci_job_date\":\"$CI_JOB_DATE\", \
    \"ci_job_id\":\"$CI_JOB_ID\", \
    \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
    \"git_commit_sha\":\"$CANDIDATE_COMMIT_SHA\", \
    \"git_commit_date\":\"$CANDIDATE_COMMIT_DATE\", \
    \"git_branch\":\"$CANDIDATE_BRANCH\"\
    }" \
    --framework=PHPBench \
    --outpath="$ARTIFACTS_DIR/candidate_${SCENARIO}_bench_opcache.converted.json" \
    "$ARTIFACTS_DIR/candidate/${SCENARIO}-bench-results-opcache.csv"

  benchmark_analyzer convert \
    --extra-params="{\
    \"baseline_or_candidate\":\"candidate\", \
    \"cpu_model\":\"$CPU_MODEL\", \
    \"kernel_version\":\"$KERNEL_VERSION\", \
    \"ci_job_date\":\"$CI_JOB_DATE\", \
    \"ci_job_id\":\"$CI_JOB_ID\", \
    \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
    \"git_commit_sha\":\"$CANDIDATE_COMMIT_SHA\", \
    \"git_commit_date\":\"$CANDIDATE_COMMIT_DATE\", \
    \"git_branch\":\"$CANDIDATE_BRANCH\"\
    }" \
    --framework=GoogleBenchmark \
    --outpath="$ARTIFACTS_DIR/candidate_${SCENARIO}_tea_bench.converted.json" \
    "$ARTIFACTS_DIR/candidate/${SCENARIO}-tea-bench-results.json"
elif [ "$SCENARIO" = "appsec" ]; then
   benchmark_analyzer convert \
    --extra-params="{\
    \"baseline_or_candidate\":\"candidate\", \
    \"cpu_model\":\"$CPU_MODEL\", \
    \"ci_job_date\":\"$CI_JOB_DATE\", \
    \"ci_job_id\":\"$CI_JOB_ID\", \
    \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
    \"git_commit_sha\":\"$CANDIDATE_COMMIT_SHA\", \
    \"git_commit_date\":\"$CANDIDATE_COMMIT_DATE\", \
    \"git_branch\":\"$CANDIDATE_BRANCH\"\
    }" \
    --framework=PHPBench \
    --outpath="$ARTIFACTS_DIR/candidate_${SCENARIO}_bench.converted.json" \
    "$ARTIFACTS_DIR/candidate/${SCENARIO}-bench-results.csv"
fi

# -- Analyze baseline branch results

BASELINE_SRC="/app/baseline/"
if [ -d $BASELINE_SRC ]; then
  BASELINE_BRANCH=$(github-find-merge-into-branch --for-repo="$UPSTREAM_PROJECT_NAME" --for-pr="$UPSTREAM_BRANCH" || :)

  # Get commit SHA for baseline branch
  cd "$BASELINE_SRC"
  BASELINE_COMMIT_SHA=$(git rev-parse HEAD)
  BASELINE_COMMIT_DATE=$(git show -s --format=%ct $BASELINE_COMMIT_SHA)

  if [ "$SCENARIO" = "profiler" ]; then
    # Analyze Profiling Benchmarks
    benchmark_analyzer convert \
      --extra-params="{\
      \"baseline_or_candidate\":\"baseline\", \
      \"cpu_model\":\"$CPU_MODEL\", \
      \"kernel_version\":\"$KERNEL_VERSION\", \
      \"ci_job_date\":\"$CI_JOB_DATE\", \
      \"ci_job_id\":\"$CI_JOB_ID\", \
      \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
      \"git_commit_sha\":\"$BASELINE_COMMIT_SHA\", \
      \"git_commit_date\":\"$BASELINE_COMMIT_DATE\", \
      \"git_branch\":\"$BASELINE_BRANCH\"\
      }" \
      --framework=Sirun \
      --outpath="$ARTIFACTS_DIR/baseline_timeline_memory.converted.json" \
      "$ARTIFACTS_DIR/baseline/sirun_timeline.ndjson"

    benchmark_analyzer convert \
      --extra-params="{\
      \"baseline_or_candidate\":\"baseline\", \
      \"cpu_model\":\"$CPU_MODEL\", \
      \"kernel_version\":\"$KERNEL_VERSION\", \
      \"ci_job_date\":\"$CI_JOB_DATE\", \
      \"ci_job_id\":\"$CI_JOB_ID\", \
      \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
      \"git_commit_sha\":\"$BASELINE_COMMIT_SHA\", \
      \"git_commit_date\":\"$BASELINE_COMMIT_DATE\", \
      \"git_branch\":\"$BASELINE_BRANCH\"\
      }" \
      --framework=Sirun \
      --outpath="$ARTIFACTS_DIR/baseline_exceptions.converted.json" \
      "$ARTIFACTS_DIR/baseline/sirun_exceptions.ndjson"

    benchmark_analyzer analyze \
      --format=md \
      --outpath="$ARTIFACTS_DIR/baseline-analysis.md" \
      "$ARTIFACTS_DIR/baseline_timeline_memory.converted.json" \
      "$ARTIFACTS_DIR/baseline_exceptions.converted.json"
  elif [ "$SCENARIO" = "tracer" ]; then
    # Analyze Tracing Benchmarks
    benchmark_analyzer convert \
      --extra-params="{\
      \"baseline_or_candidate\":\"baseline\", \
      \"cpu_model\":\"$CPU_MODEL\", \
      \"kernel_version\":\"$KERNEL_VERSION\", \
      \"ci_job_date\":\"$CI_JOB_DATE\", \
      \"ci_job_id\":\"$CI_JOB_ID\", \
      \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
      \"git_commit_sha\":\"$BASELINE_COMMIT_SHA\", \
      \"git_commit_date\":\"$BASELINE_COMMIT_DATE\", \
      \"git_branch\":\"$BASELINE_BRANCH\"\
      }" \
      --framework=PHPBench \
      --outpath="$ARTIFACTS_DIR/baseline_${SCENARIO}_bench.converted.json" \
      "$ARTIFACTS_DIR/baseline/${SCENARIO}-bench-results.csv"

    benchmark_analyzer convert \
      --extra-params="{\
      \"baseline_or_candidate\":\"baseline\", \
      \"cpu_model\":\"$CPU_MODEL\", \
      \"kernel_version\":\"$KERNEL_VERSION\", \
      \"ci_job_date\":\"$CI_JOB_DATE\", \
      \"ci_job_id\":\"$CI_JOB_ID\", \
      \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
      \"git_commit_sha\":\"$BASELINE_COMMIT_SHA\", \
      \"git_commit_date\":\"$BASELINE_COMMIT_DATE\", \
      \"git_branch\":\"$BASELINE_BRANCH\"\
      }" \
      --framework=PHPBench \
      --outpath="$ARTIFACTS_DIR/baseline_${SCENARIO}_bench_opcache.converted.json" \
      "$ARTIFACTS_DIR/baseline/${SCENARIO}-bench-results-opcache.csv"

    benchmark_analyzer convert \
      --extra-params="{\
      \"baseline_or_candidate\":\"baseline\", \
      \"cpu_model\":\"$CPU_MODEL\", \
      \"kernel_version\":\"$KERNEL_VERSION\", \
      \"ci_job_date\":\"$CI_JOB_DATE\", \
      \"ci_job_id\":\"$CI_JOB_ID\", \
      \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
      \"git_commit_sha\":\"$BASELINE_COMMIT_SHA\", \
      \"git_commit_date\":\"$BASELINE_COMMIT_DATE\", \
      \"git_branch\":\"$BASELINE_BRANCH\"\
      }" \
      --framework=GoogleBenchmark \
      --outpath="$ARTIFACTS_DIR/baseline_${SCENARIO}_tea_bench.converted.json" \
      "$ARTIFACTS_DIR/baseline/${SCENARIO}-tea-bench-results.json"
  elif [ "$SCENARIO" = "appsec" ]; then
     benchmark_analyzer convert \
      --extra-params="{\
      \"baseline_or_candidate\":\"baseline\", \
      \"cpu_model\":\"$CPU_MODEL\", \
      \"ci_job_date\":\"$CI_JOB_DATE\", \
      \"ci_job_id\":\"$CI_JOB_ID\", \
      \"ci_pipeline_id\":\"$CI_PIPELINE_ID\", \
      \"git_commit_sha\":\"$BASELINE_COMMIT_SHA\", \
      \"git_commit_date\":\"$BASELINE_COMMIT_DATE\", \
      \"git_branch\":\"$BASELINE_BRANCH\"\
      }" \
      --framework=PHPBench \
      --outpath="$ARTIFACTS_DIR/baseline_${SCENARIO}_bench.converted.json" \
      "$ARTIFACTS_DIR/baseline/${SCENARIO}-bench-results.csv"
  fi
fi

# -- Compare results between baseline and candidate branches

if [ -d $BASELINE_SRC ]; then
  if [ "$SCENARIO" = "profiler" ]; then
    benchmark_analyzer compare pairwise \
      --baseline='{"baseline_or_candidate":"baseline"}' \
      --candidate='{"baseline_or_candidate":"candidate"}' \
      --format=md-nodejs \
      --outpath="$ARTIFACTS_DIR/comparison-baseline-vs-candidate.md" \
      "$ARTIFACTS_DIR/baseline_timeline_memory.converted.json" "$ARTIFACTS_DIR/candidate_timeline_memory.converted.json" \
      "$ARTIFACTS_DIR/baseline_exceptions.converted.json" "$ARTIFACTS_DIR/candidate_exceptions.converted.json"
  elif [ "$SCENARIO" = "tracer" ]; then
    benchmark_analyzer compare pairwise \
      --baseline='{"baseline_or_candidate":"baseline"}' \
      --candidate='{"baseline_or_candidate":"candidate"}' \
      --format=md-nodejs \
      --outpath="$ARTIFACTS_DIR/comparison-baseline-vs-candidate.md" \
      "$ARTIFACTS_DIR/baseline_${SCENARIO}_bench.converted.json" "$ARTIFACTS_DIR/candidate_${SCENARIO}_bench.converted.json" \
      "$ARTIFACTS_DIR/baseline_${SCENARIO}_bench_opcache.converted.json" "$ARTIFACTS_DIR/candidate_${SCENARIO}_bench_opcache.converted.json" \
      "$ARTIFACTS_DIR/baseline_${SCENARIO}_tea_bench.converted.json" "$ARTIFACTS_DIR/candidate_${SCENARIO}_tea_bench.converted.json"
  elif [ "$SCENARIO" = "appsec" ]; then
      benchmark_analyzer compare pairwise \
      --baseline='{"baseline_or_candidate":"baseline"}' \
      --candidate='{"baseline_or_candidate":"candidate"}' \
      --format=md-nodejs \
      --outpath="$ARTIFACTS_DIR/comparison-baseline-vs-candidate.md" \
      "$ARTIFACTS_DIR/baseline_${SCENARIO}_bench.converted.json" "$ARTIFACTS_DIR/candidate_${SCENARIO}_bench.converted.json"
  fi
fi

