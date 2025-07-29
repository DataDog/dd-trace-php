#!/usr/bin/env bash
set -eo pipefail

# Try to update repositories, but don't fail if repositories are unavailable
set +e
sudo apt update 2>/dev/null || echo "Warning: apt update failed, but continuing with existing packages"
set -e

# Try to install jq, but check if it's already available first
if ! command -v jq &> /dev/null; then
    set +e
    sudo apt install -y jq 2>/dev/null || {
        echo "Warning: Could not install jq via apt, trying alternative installation"
        # Try alternative installation method for jq
        wget -O jq https://github.com/jqlang/jq/releases/latest/download/jq-linux64 2>/dev/null && chmod +x jq && sudo mv jq /usr/local/bin/ || {
            echo "Error: Could not install jq. Skipping test agent checks."
            exit 0
        }
    }
    set -e
else
    echo "jq is already available"
fi

set +e  # Disable exiting from testagent response failure
SUMMARY_RESPONSE=$(curl -s -w "\n%{http_code}" -o summary_response.txt http://test-agent:9126/test/trace_check/summary)
set -e
SUMMARY_RESPONSE_CODE=$(echo "$SUMMARY_RESPONSE" | awk 'END {print $NF}')
if [[ SUMMARY_RESPONSE_CODE -eq 200 ]]; then
  echo "APM Test Agent is running. (HTTP 200)"
else
  echo "APM Test Agent is not running and was not used for testing. No checks failed."
  exit 0
fi

RESPONSE=$(curl -s -w "\n%{http_code}" -o response.txt http://test-agent:9126/test/trace_check/failures)
RESPONSE_CODE=$(echo "$RESPONSE" | awk 'END {print $NF}')

if [[ $RESPONSE_CODE -eq 200 ]]; then
  echo "All APM Test Agent Check Traces returned successful! (HTTP 200)"
  echo "APM Test Agent Check Traces Summary Results:"
  cat summary_response.txt | jq '.'
elif [[ $RESPONSE_CODE -eq 404 ]]; then
  echo "Real APM Agent running in place of TestAgent, no checks to validate!"
else
  echo "APM Test Agent Check Traces failed with response code: $RESPONSE_CODE"
  echo "Failures:"
  cat response.txt
  echo "APM Test Agent Check Traces Summary Results:"
  cat summary_response.txt | jq '.'
  exit 1
fi  
