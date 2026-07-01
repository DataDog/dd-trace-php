#!/usr/bin/env bash
# Merge gate: passes iff every non-flaky job in this pipeline (and its triggered
# child pipelines) succeeded. It collects failed jobs via the GitLab API and
# classifies each against the glob patterns in .gitlab/flaky-jobs.txt; a failure
# matching no pattern is a real regression and fails the gate. See the
# `merge-gate` job in .gitlab-ci.yml.
set -uo pipefail

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
  | jq -r '.token')
GITLAB_API="https://gitlab.ddbuild.io/api/v4"
AUTH="PRIVATE-TOKEN: ${GITLAB_TOKEN}"

# Pipelines to inspect: this parent pipeline + every triggered child.
pipelines=("${CI_PIPELINE_ID}")
bridges=$(curl -sf -H "${AUTH}" \
  "${GITLAB_API}/projects/${CI_PROJECT_ID}/pipelines/${CI_PIPELINE_ID}/bridges?per_page=100" || echo "[]")
while read -r child; do
  [ -n "${child}" ] && pipelines+=("${child}")
done < <(echo "${bridges}" | jq -r '.[] | select(.downstream_pipeline != null) | .downstream_pipeline.id')

# Collect the names of all failed jobs across those pipelines.
: > failed_jobs.txt
for pid in "${pipelines[@]}"; do
  for page in 1 2 3 4 5; do
    data=$(curl -g -sf -H "${AUTH}" \
      "${GITLAB_API}/projects/${CI_PROJECT_ID}/pipelines/${pid}/jobs?scope[]=failed&per_page=100&page=${page}" || echo "[]")
    echo "${data}" | jq -r '.[] | select(.status == "failed") | .name' >> failed_jobs.txt
    [ "$(echo "${data}" | jq 'length')" -lt 100 ] && break
  done
done
sort -u failed_jobs.txt -o failed_jobs.txt

# Load flaky globs and classify each failure.
mapfile -t GLOBS < <(grep -vE '^[[:space:]]*(#|$)' .gitlab/flaky-jobs.txt)
echo "Loaded ${#GLOBS[@]} flaky patterns; $(wc -l < failed_jobs.txt) distinct failed job(s)."
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
