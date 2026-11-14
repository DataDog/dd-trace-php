#!/usr/bin/env bash
# Merge gate: passes iff every required job in this pipeline (and its triggered
# child pipelines) succeeded. Required means: not allow_failure, and not matching
# a glob in .gitlab/flaky-jobs.txt. A failure that is neither is a real
# regression and fails the gate. See the `merge-gate` job in .gitlab-ci.yml.
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

# A retried job hides its failed attempt from `scope[]=failed` until it finishes, so first wait
# (bounded) until nothing but this job is in flight. API errors exit 75; a timeout exits 1.
INFLIGHT_STATUSES=(created waiting_for_resource preparing pending running scheduled)
INFLIGHT_SCOPES=$(printf '&scope[]=%s' "${INFLIGHT_STATUSES[@]}")
INFLIGHT_POLL_SECONDS=30
# Job timeout minus startup (~80 s), classification, and a stalled poll and re-check (~100 s each).
INFLIGHT_MAX_WAIT_SECONDS=$(( ${CI_JOB_TIMEOUT:-3600} - 600 ))
# A stalled or throttled call fails within 92 s (60 s retry window + 2 s delay + 30 s try); exit 75.
_gitlab_get() {
  curl -g -sf --connect-timeout 10 --max-time 30 --retry 5 --retry-delay 2 --retry-max-time 60 \
    --retry-connrefused -H "${AUTH}" "${GITLAB_API}/projects/${CI_PROJECT_ID}/$1"
}
# Prints one line per in-flight job (parent + children) or parent bridge, minus parent `.post` (runs after
# the gate); fails on API errors and non-list bodies. One page per pipeline is enough: any hit means wait.
_list_inflight() {
  local bridges children jobs child pid pids=("${CI_PIPELINE_ID}")
  bridges=$(_gitlab_get "pipelines/${CI_PIPELINE_ID}/bridges?per_page=100") || return 1
  children=$(echo "${bridges}" | jq -rn 'input | if type == "array" then .[] else error("not a list") end
    | select(.downstream_pipeline != null) | .downstream_pipeline.id') || return 1
  while read -r child; do
    [ -n "${child}" ] && pids+=("${child}")
  done <<< "${children}"
  echo "${bridges}" | jq -rn --arg st "${INFLIGHT_STATUSES[*]}" --arg p "${CI_PIPELINE_ID}" \
    'input | if type == "array" then .[] else error("not a list") end
    | select(.stage != ".post" and (.status as $s | $st | split(" ") | any(. == $s)))
    | "  … \(.status): \(.name) (pipeline \($p))"' || return 1
  for pid in "${pids[@]}"; do
    jobs=$(_gitlab_get "pipelines/${pid}/jobs?per_page=100${INFLIGHT_SCOPES}") || return 1
    echo "${jobs}" | jq -rn --arg self "${CI_JOB_ID}" --arg p "${pid}" --arg parent "${CI_PIPELINE_ID}" \
      'input | if type == "array" then .[] else error("not a list") end
      | select((.id | tostring) != $self and ($p != $parent or .stage != ".post"))
      | "  … \(.status): \(.name) (pipeline \($p))"' || return 1
  done
}
wait_start=${SECONDS}
previous=""
while :; do
  inflight=$(_list_inflight) || { echo "ERROR: could not list in-flight jobs" >&2; exit 75; }
  [ -z "${inflight}" ] && break
  if [ $((SECONDS - wait_start)) -ge "${INFLIGHT_MAX_WAIT_SECONDS}" ]; then
    echo "Merge gate FAILED — job(s) still in flight after $((SECONDS - wait_start))s; retry merge-gate once they finish:"
    echo "${inflight}"
    exit 1
  fi
  echo "Waiting for $(wc -l <<< "${inflight}") in-flight job(s), $((SECONDS - wait_start))s elapsed."
  [ "${inflight}" != "${previous}" ] && echo "${inflight}"
  previous=${inflight}
  sleep "${INFLIGHT_POLL_SECONDS}"
done
[ -n "${previous}" ] && echo "Nothing in flight after $((SECONDS - wait_start))s."

# Pipelines to inspect: this parent pipeline + every triggered child.
pipelines=("${CI_PIPELINE_ID}")
bridges=$(curl -sf -H "${AUTH}" \
  "${GITLAB_API}/projects/${CI_PROJECT_ID}/pipelines/${CI_PIPELINE_ID}/bridges?per_page=100" || echo "[]")
while read -r child; do
  [ -n "${child}" ] && pipelines+=("${child}")
done < <(echo "${bridges}" | jq -r '.[] | select(.downstream_pipeline != null) | .downstream_pipeline.id')

# Collect the names of all failed jobs across those pipelines. `scope[]=failed`
# also returns allow_failure jobs (their status is "failed" too).
: > failed_jobs.txt
: > allowed_failures.txt
for pid in "${pipelines[@]}"; do
  for page in 1 2 3 4 5; do
    data=$(curl -g -sf -H "${AUTH}" \
      "${GITLAB_API}/projects/${CI_PROJECT_ID}/pipelines/${pid}/jobs?scope[]=failed&per_page=100&page=${page}" || echo "[]")
    echo "${data}" | jq -r '.[] | select(.status == "failed" and .allow_failure != true) | .name' >> failed_jobs.txt
    echo "${data}" | jq -r '.[] | select(.status == "failed" and .allow_failure == true) | .name' >> allowed_failures.txt
    [ "$(echo "${data}" | jq 'length')" -lt 100 ] && break
  done
done
sort -u failed_jobs.txt -o failed_jobs.txt
sort -u allowed_failures.txt -o allowed_failures.txt

# Load flaky globs and classify each failure.
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
# A job (re)started after the wait could hide a failure from the query above, so never pass then.
inflight=$(_list_inflight) || { echo "ERROR: could not list in-flight jobs" >&2; exit 75; }
if [ -n "${inflight}" ]; then
  echo ""
  echo "Merge gate FAILED — job(s) started while judging; retry merge-gate:"
  echo "${inflight}"
  exit 1
fi
echo ""
echo "Merge gate PASSED — no failures, or all failures are known-flaky."
