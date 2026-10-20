#!/usr/bin/env bash
# Resolve system-tests main and the ci-runner image its CI uses at that commit.
# Usage: resolve-system-tests-ref.sh [DOTENV_FILE]; prints the SYSTEM_TESTS_SHA and SYSTEM_TESTS_CI_IMAGE dotenv lines, also to DOTENV_FILE.
set -euo pipefail

ST_REPO=https://github.com/DataDog/system-tests.git
SYSTEM_TESTS_SHA=$(git ls-remote "$ST_REPO" refs/heads/main | awk 'NR == 1 { print $1 }')
if ! printf '%s\n' "$SYSTEM_TESTS_SHA" | grep -Eq '^[0-9a-f]{40}$'; then
  echo "Failed to resolve a valid system-tests commit: $SYSTEM_TESTS_SHA" >&2
  exit 1
fi
git init -q /tmp/system-tests
git -C /tmp/system-tests fetch -q --depth 50 --filter=blob:none "$ST_REPO" "$SYSTEM_TESTS_SHA"
# The ci-runner image system-tests' own CI uses at a commit (its venv matches that checkout).
st_image() {
  git -C /tmp/system-tests show "$1:utils/ci/gitlab/main.yml" | sed -n 's/^ *CI_IMAGE: *"\{0,1\}\([^" ]*ci-runner:[0-9a-f]\{12\}\)"\{0,1\}.*/\1/p' | head -n1 | grep . ||
    { echo "No ci-runner CI_IMAGE in system-tests utils/ci/gitlab/main.yml at $1" >&2; return 1; }
}
SYSTEM_TESTS_CI_IMAGE=$(st_image "$SYSTEM_TESTS_SHA") || exit 1
REGISTRY_TOKEN=$(curl -fsS --max-time 20 "https://registry-auth.ddbuild.io/token?service=registry.ddbuild.io&scope=repository:system-tests/ci-runner:pull" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p') || true
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -I -H "Authorization: Bearer $REGISTRY_TOKEN" \
  -H 'Accept: application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.oci.image.index.v1+json' \
  "https://registry.ddbuild.io/v2/system-tests/ci-runner/manifests/${SYSTEM_TESTS_CI_IMAGE##*:}") || true
if [ "$HTTP_CODE" = 404 ]; then
  # A just-merged runner change whose image is not pushed yet: test the commit before that change.
  CHANGE=$(git -C /tmp/system-tests log -1 --format=%H "$SYSTEM_TESTS_SHA" -- utils/ci/gitlab/docker/system-tests.Dockerfile requirements.txt)
  SYSTEM_TESTS_SHA=$(git -C /tmp/system-tests rev-parse "$CHANGE^")
  echo "$SYSTEM_TESTS_CI_IMAGE (system-tests $CHANGE) is not published yet; using system-tests $SYSTEM_TESTS_SHA" >&2
  SYSTEM_TESTS_CI_IMAGE=$(st_image "$SYSTEM_TESTS_SHA") || exit 1
elif [ "$HTTP_CODE" != 200 ]; then
  echo "Warning: could not check $SYSTEM_TESTS_CI_IMAGE in the registry (HTTP $HTTP_CODE); using it anyway" >&2
fi
printf 'SYSTEM_TESTS_SHA=%s\nSYSTEM_TESTS_CI_IMAGE=%s\n' "$SYSTEM_TESTS_SHA" "$SYSTEM_TESTS_CI_IMAGE" | tee ${1:+"$1"}
