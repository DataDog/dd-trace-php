#!/bin/bash
# Check status of helper-rust CI jobs in a GitLab pipeline
# Usage: check-ci-jobs.sh <pipeline_id> [job_filter]
# Example: check-ci-jobs.sh 91141215 helper-rust
# Note: Use the numeric pipeline ID (not IID) from the child appsec pipeline

set -e

PIPELINE_ID="${1:?Pipeline ID required}"
JOB_FILTER="${2:-helper-rust}"
PROJECT_ID="355"
GITLAB_URL="https://gitlab.ddbuild.io"

# Get token from MCP config
GITLAB_TOKEN=$(jq -r '.mcpServers.gitlab.env.GITLAB_PERSONAL_ACCESS_TOKEN' ~/.claude.json)

if [ -z "$GITLAB_TOKEN" ] || [ "$GITLAB_TOKEN" = "null" ]; then
    echo "ERROR: Could not extract GitLab token from ~/.claude.json"
    exit 1
fi

# Get jobs matching filter
JOBS=$(curl -s -H "PRIVATE-TOKEN: $GITLAB_TOKEN" \
    "$GITLAB_URL/api/v4/projects/$PROJECT_ID/pipelines/$PIPELINE_ID/jobs?per_page=100" | \
    jq -r ".[] | select(.name | contains(\"$JOB_FILTER\"))")

if [ -z "$JOBS" ] || [ "$JOBS" = "" ]; then
    echo "ERROR: No jobs found matching filter '$JOB_FILTER' in pipeline $PIPELINE_ID"
    exit 1
fi

# Count statuses
TOTAL=$(echo "$JOBS" | jq -s 'length')
RUNNING=$(echo "$JOBS" | jq -s '[.[] | select(.status == "running" or .status == "pending" or .status == "created")] | length')
PASSED=$(echo "$JOBS" | jq -s '[.[] | select(.status == "success")] | length')
FAILED=$(echo "$JOBS" | jq -s '[.[] | select(.status == "failed")] | length')

# Output summary
echo "PIPELINE_ID=$PIPELINE_ID"
echo "TOTAL=$TOTAL"
echo "RUNNING=$RUNNING"
echo "PASSED=$PASSED"
echo "FAILED=$FAILED"

# Output job details
echo "---JOBS---"
echo "$JOBS" | jq -r '[.name, .status, .id] | @tsv'
