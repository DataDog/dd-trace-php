#!/usr/bin/env bash
set -eu

# Some benchmark runner clusters lack the project's service account. Check
# credentials before bp-runner starts the agent with an empty API key.
if [ -z "${DD_API_KEY:-}" ]; then
    aws_error=$(mktemp)
    trap 'rm -f -- "$aws_error"' EXIT
    if DD_API_KEY=$(aws ssm get-parameter --region us-east-1 \
        --name "ci.${CI_PROJECT_NAME}.dd_api_key" --with-decryption \
        --query Parameter.Value --out text 2>"$aws_error"); then
        if [ -z "$DD_API_KEY" ] || [ "$DD_API_KEY" = "None" ]; then
            echo "The benchmark agent API key is empty." >&2
            exit 1
        fi
    else
        status=$?
        cat "$aws_error" >&2
        if grep -Fq 'Unable to locate credentials' "$aws_error"; then
            echo "Runner AWS credentials are missing; exiting 75 for GitLab to retry." >&2
            exit 75
        fi
        exit "$status"
    fi
    rm -f -- "$aws_error"
    trap - EXIT
fi

# The platform's agent setup reuses DD_API_KEY, avoiding another secret lookup.
export DD_API_KEY
exec bp-runner "$@"
