#!/usr/bin/env bash
set -eo pipefail

MAX_RETRIES=5
RETRY_DELAY=3

check_test_agent_with_retry() {
  local endpoint=$1
  local output_file=$2
  local attempt=1

  while [ $attempt -le $MAX_RETRIES ]; do
    set +e
    response=$(curl -s -w "\n%{http_code}" -o "$output_file" "http://test-agent:9126$endpoint")
    response_code=$(echo "$response" | awk 'END {print $NF}')
    set -e

    if [[ $response_code -eq 200 ]] || [[ $response_code -eq 404 ]]; then
      echo "$response_code"
      return 0
    fi

    echo "Attempt $attempt/$MAX_RETRIES: Test agent returned $response_code, retrying in ${RETRY_DELAY}s..." >&2
    sleep $RETRY_DELAY
    attempt=$((attempt + 1))
  done

  echo "0"  # Failed after all retries
  return 1
}

# Check if test agent is running
summary_code=$(check_test_agent_with_retry "/test/trace_check/summary" "summary_response.txt")

if [[ $summary_code -eq 200 ]]; then
  echo "APM Test Agent is running. (HTTP 200)"
elif [[ $summary_code -eq 404 ]]; then
  echo "Real APM Agent running in place of TestAgent, no checks to validate!"
  exit 0
else
  echo "APM Test Agent is not running and was not used for testing. No checks failed."
  exit 0
fi

# Check for failures with retry
failures_code=$(check_test_agent_with_retry "/test/trace_check/failures" "response.txt")

if [[ $failures_code -eq 200 ]]; then
  echo "All APM Test Agent Check Traces returned successful! (HTTP 200)"
  echo "APM Test Agent Check Traces Summary Results:"
  cat summary_response.txt | jq '.'
elif [[ $failures_code -eq 404 ]]; then
  echo "Real APM Agent running in place of TestAgent, no checks to validate!"
else
  echo "APM Test Agent Check Traces failed with response code: $failures_code"
  echo "Failures:"
  cat response.txt
  echo "APM Test Agent Check Traces Summary Results:"
  cat summary_response.txt | jq '.'
  exit 1
fi
