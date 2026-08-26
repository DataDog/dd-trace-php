#!/usr/bin/env bash
# Merge gate: passes iff every required job in this pipeline (and its triggered
# child pipelines) succeeded. Required means: not allow_failure, and not matching
# a glob in .gitlab/flaky-jobs.txt. A failure that is neither is a real
# regression and fails the gate. See the `merge-gate` job in .gitlab-ci.yml.
#
# The gate must fail closed: an API error or a truncated result aborts, it is
# never reported as "nothing failed".
set -euo pipefail

# Short-lived GitLab API token, same path as `analyze and create pr`
# (Vault-issued JWT for the 'sdm' audience -> BTI CI API).
_vault_jwt() {
  local audience="$1"
  if [ -n "${VAULT_ADDR:-}" ]; then
    curl -sf -H "X-Vault-Request: true" \
      "${VAULT_ADDR}/v1/identity/oidc/token/${audience}" | jq -r '.data.token' 2>/dev/null && return 0
  fi
  if [ -n "${DD_DATACENTER:-}" ]; then
    curl -sf -H "X-Vault-Request: true" \
      "https://vault.${DD_DATACENTER}/v1/identity/oidc/token/${audience}" | jq -r '.data.token' 2>/dev/null && return 0
  fi
  return 1
}
BTI_JWT=$(_vault_jwt sdm) || { echo "ERROR: could not obtain a BTI JWT" >&2; exit 1; }
GITLAB_TOKEN=$(curl -sf -H "Authorization: Bearer ${BTI_JWT}" \
  "https://bti-ci-api.us1.ddbuild.io/internal/ci/gitlab/token?owner=DataDog&repository=dd-trace-php" \
  | jq -r '.token // empty') || GITLAB_TOKEN=""
[ -n "${GITLAB_TOKEN}" ] || { echo "ERROR: could not obtain a GitLab API token" >&2; exit 1; }
GITLAB_API="https://gitlab.ddbuild.io/api/v4"
AUTH="PRIVATE-TOKEN: ${GITLAB_TOKEN}"

# GET with a bounded retry; a non-zero exit means API error, which callers must
# not confuse with an empty (i.e. "nothing failed") result.
api_get() {
  local url="$1" attempt body
  for attempt in 1 2 3; do
    body=$(curl -g -sf -H "${AUTH}" "${url}") && { printf '%s' "${body}"; return 0; }
    sleep 5
  done
  return 1
}

# Pipelines to inspect: this parent pipeline + every triggered child.
pipelines=("${CI_PIPELINE_ID}")
bridges=$(api_get "${GITLAB_API}/projects/${CI_PROJECT_ID}/pipelines/${CI_PIPELINE_ID}/bridges?per_page=100") \
  || { echo "ERROR: could not list the child pipelines of ${CI_PIPELINE_ID}" >&2; exit 1; }
children=$(jq -r '.[] | select(.downstream_pipeline != null) | .downstream_pipeline.id' <<<"${bridges}")
while read -r child; do
  [ -n "${child}" ] && pipelines+=("${child}")
done <<<"${children}"

# Collect the names of all failed jobs across those pipelines. `scope[]=failed`
# also returns allow_failure jobs (their status is "failed" too).
: > failed_jobs.txt
: > allowed_failures.txt
for pid in "${pipelines[@]}"; do
  for page in 1 2 3 4 5; do
    data=$(api_get "${GITLAB_API}/projects/${CI_PROJECT_ID}/pipelines/${pid}/jobs?scope[]=failed&per_page=100&page=${page}") \
      || { echo "ERROR: could not list the failed jobs of pipeline ${pid} (page ${page})" >&2; exit 1; }
    jq -r '.[] | select(.status == "failed" and .allow_failure != true) | .name' <<<"${data}" >> failed_jobs.txt
    jq -r '.[] | select(.status == "failed" and .allow_failure == true) | .name' <<<"${data}" >> allowed_failures.txt
    [ "$(jq 'length' <<<"${data}")" -lt 100 ] && break
    # Don't judge a truncated list.
    [ "${page}" -lt 5 ] || { echo "ERROR: pipeline ${pid} has more than 500 failed jobs" >&2; exit 1; }
  done
done
sort -u failed_jobs.txt -o failed_jobs.txt
sort -u allowed_failures.txt -o allowed_failures.txt

# Load flaky globs and classify each failure.
[ -f .gitlab/flaky-jobs.txt ] || { echo "ERROR: .gitlab/flaky-jobs.txt not found" >&2; exit 1; }
mapfile -t GLOBS < <(grep -vE '^[[:space:]]*(#|$)' .gitlab/flaky-jobs.txt)
echo "Loaded ${#GLOBS[@]} flaky patterns; $(wc -l < failed_jobs.txt) required failed job(s), $(wc -l < allowed_failures.txt) allowed-to-fail."
while IFS= read -r job; do
  [ -n "${job}" ] && echo "  ~ allowed to fail:   ${job}"
done < allowed_failures.txt
blocking=0
while IFS= read -r job; do
  [ -z "${job}" ] && continue
  [ "${job}" = "merge-gate" ] && continue
  ok=0
  for g in "${GLOBS[@]}"; do
    if [[ "${job}" == $g ]]; then ok=1; break; fi
  done
  if [ "${ok}" -eq 0 ]; then
    echo "  ✗ non-flaky failure: ${job}"
    blocking=1
  else
    echo "  ✓ known-flaky:       ${job}"
  fi
done < failed_jobs.txt

if [ "${blocking}" -ne 0 ]; then
  echo ""
  echo "Merge gate FAILED — a required (non-flaky) job failed. See ✗ lines above."
  exit 1
fi
echo ""
echo "Merge gate PASSED — no failures, or all failures are known-flaky."
