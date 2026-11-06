#!/usr/bin/env bash
# Resolve a system-tests ref and the ci-runner image its CI uses at that commit.
# Usage: resolve-system-tests-ref.sh [DOTENV_FILE]; prints the SYSTEM_TESTS_SHA and SYSTEM_TESTS_CI_IMAGE dotenv lines, also to DOTENV_FILE.
set -euo pipefail

ST_REPO=https://github.com/DataDog/system-tests.git
# SYSTEM_TESTS_REF (default main) lets a pipeline try a system-tests change before it is merged:
# a system-tests branch, tag or SHA from 2026-07 on, whose main.yml CI_IMAGE is published.
git init -q /tmp/system-tests
if ! err=$(git -C /tmp/system-tests fetch -q --depth 50 --filter=blob:none -- "$ST_REPO" "${SYSTEM_TESTS_REF:-main}" 2>&1); then
  echo "$err" >&2
  # An unknown ref or SHA exits 1, which is not retried; other fetch failures exit 128, which is.
  case $err in *"couldn't find remote ref"*|*"not our ref"*) exit 1;; esac
  exit 128
fi
SYSTEM_TESTS_SHA=$(git -C /tmp/system-tests rev-parse 'FETCH_HEAD^{commit}')
# The ci-runner image system-tests' own CI uses at a commit (its venv matches that checkout).
st_image() {
  git -C /tmp/system-tests ls-tree --name-only "$1" utils/ci/gitlab/main.yml | grep -q . ||
    { echo "system-tests $1 has no utils/ci/gitlab/main.yml (older than the ci-runner image)" >&2; return 1; }
  # 75 is retried: the blob is fetched lazily from github.com.
  yml=$(git -C /tmp/system-tests show "$1:utils/ci/gitlab/main.yml") || return 75
  sed -n 's/^ *CI_IMAGE: *"\{0,1\}\([^" ]*ci-runner:[0-9a-f]\{12\}\)"\{0,1\}.*/\1/p' <<<"$yml" | head -n1 | grep . ||
    { echo "No ci-runner CI_IMAGE in system-tests utils/ci/gitlab/main.yml at $1" >&2; return 1; }
}
SYSTEM_TESTS_CI_IMAGE=$(st_image "$SYSTEM_TESTS_SHA") || exit $?
REGISTRY_TOKEN=$(curl -fsS --max-time 20 "https://registry-auth.ddbuild.io/token?service=registry.ddbuild.io&scope=repository:system-tests/ci-runner:pull" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p') || true
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -I -H "Authorization: Bearer $REGISTRY_TOKEN" \
  -H 'Accept: application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.oci.image.index.v1+json' \
  "https://registry.ddbuild.io/v2/system-tests/ci-runner/manifests/${SYSTEM_TESTS_CI_IMAGE##*:}") || true
if [ "$HTTP_CODE" = 404 ]; then
  if [ "${SYSTEM_TESTS_REF:-main}" != main ]; then
    echo "$SYSTEM_TESTS_CI_IMAGE (SYSTEM_TESTS_REF=$SYSTEM_TESTS_REF) is not published; wait for system-tests' build_ci_image job" >&2
    exit 1
  fi
  # A just-merged runner change whose image is not pushed yet: test the commit before that change.
  RUNNER_FILES=(utils/ci/gitlab/docker/system-tests.Dockerfile requirements.txt)
  CHANGE=$(git -C /tmp/system-tests log -1 --format=%H "$SYSTEM_TESTS_SHA" -- "${RUNNER_FILES[@]}")
  if ! git -C /tmp/system-tests rev-parse -q --verify "$CHANGE^" >/dev/null; then
    # Past the fetched depth, git log stops at the shallow root, which has no parent: deepen once.
    git -C /tmp/system-tests fetch -q --deepen=500 --filter=blob:none -- "$ST_REPO" "$SYSTEM_TESTS_SHA" || exit 128
    CHANGE=$(git -C /tmp/system-tests log -1 --format=%H "$SYSTEM_TESTS_SHA" -- "${RUNNER_FILES[@]}")
  fi
  if PARENT=$(git -C /tmp/system-tests rev-parse -q --verify "$CHANGE^"); then
    echo "$SYSTEM_TESTS_CI_IMAGE (system-tests $CHANGE) is not published yet; using system-tests $PARENT" >&2
    SYSTEM_TESTS_SHA=$PARENT
    SYSTEM_TESTS_CI_IMAGE=$(st_image "$SYSTEM_TESTS_SHA") || exit $?
  else
    # Do not block packaging: only the System Tests jobs, which run on this image, fail.
    echo "Warning: $SYSTEM_TESTS_CI_IMAGE is not published and the runner change behind it is not in the fetched history; keeping system-tests $SYSTEM_TESTS_SHA" >&2
  fi
elif [ "$HTTP_CODE" != 200 ]; then
  echo "Warning: could not check $SYSTEM_TESTS_CI_IMAGE in the registry (HTTP $HTTP_CODE); using it anyway" >&2
fi
printf 'SYSTEM_TESTS_SHA=%s\nSYSTEM_TESTS_CI_IMAGE=%s\n' "$SYSTEM_TESTS_SHA" "$SYSTEM_TESTS_CI_IMAGE" | tee ${1:+"$1"}
