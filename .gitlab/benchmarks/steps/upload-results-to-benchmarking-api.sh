#!/usr/bin/env bash

PROJECT=dd-trace-php

function upload_results_to_api() {
  set -e

  PREFIX=$1
  BASELINE_PREFIX=$2
  CONVERTED_JSON="$PREFIX.converted.json"

  if [ ! -f "$CONVERTED_JSON" ]; then
    echo "FAIL! ${CONVERTED_JSON} was not found, uploading failed!"
    return
  fi

  if [ -f "${BASELINE_PREFIX}.converted.json" ]; then
    BASELINE_COMMIT_SHA=$(cat "${BASELINE_PREFIX}.converted.json" | jq -c '.benchmarks[0].parameters.git_commit_sha' | tr -d '''"' )
    BASELINE_CI_PIPELINE_ID=$(cat "${BASELINE_PREFIX}.converted.json" | jq -c '.benchmarks[0].parameters.ci_pipeline_id' | tr -d '''"' )
  fi

  STATUSCODE=$(curl -s --output /dev/stderr --write-out "%{http_code}" --form file=@"$CONVERTED_JSON" "https://benchmarking-service.us1.prod.dog/benchmarks/upload/$PROJECT?baseline_commit_sha=$BASELINE_COMMIT_SHA&baseline_ci_pipeline_id=$BASELINE_CI_PIPELINE_ID")
  if test $STATUSCODE -ne 200; then
    exit 1
  fi

  echo "SUCCESS! Uploaded $CONVERTED_JSON to Benchmarking Platform service!"
}

cd "$ARTIFACTS_DIR"
for fcandidate in candidate_*.converted.json; do
    [ -e "$fcandidate" ] || continue

    fcandidate=$(echo "$fcandidate" | sed "s#$ARTIFACTS_DIR/##g" | sed "s#\\.converted\\.json##g")
    fbaseline=$(echo "$fcandidate" | sed "s#candidate#baseline#g")

    if [[ -f "$fbaseline.converted.json" ]]; then
      upload_results_to_api $fbaseline ""
      upload_results_to_api $fcandidate $fbaseline
    else
      upload_results_to_api $fcandidate ""
    fi
done

for fcandidate in candidate_*.converted.json; do
  [ -e "$fcandidate" ] || continue

  CANDIDATE_COMMIT_SHA=$(cat "$fcandidate" | jq -c '.benchmarks[0].parameters.git_commit_sha' | tr -d '''"' )
  CANDIDATE_CI_PIPELINE_ID=$(cat "$fcandidate" | jq -c '.benchmarks[0].parameters.ci_pipeline_id' | tr -d '''"' )

  echo ""
  echo "------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------"
  echo ""
  echo ""
  echo "See the results in BP UI: https://benchmarking.us1.prod.dog/benchmarks?benchmarkGroupPipelineId=$CANDIDATE_CI_PIPELINE_ID&benchmarkGroupSha=$CANDIDATE_COMMIT_SHA"
  echo ""
  echo ""
  echo "------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------"
  echo ""

  break
done
