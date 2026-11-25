#!/usr/bin/env bash

set -euo pipefail

STEPS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BOLT_MODE=${BOLT_MODE:-}
case "$BOLT_MODE" in
  ''|off|plain|align) ;;
  *) echo "BOLT_MODE must be off, plain, or align." >&2; exit 1 ;;
esac

WORK_DIR=$(mktemp -d)
export BOLT_DIR="$WORK_DIR/bolt"
cleanup() {
  # Retain diagnostics, without archiving copies of the extension binaries.
  if [ -d "$BOLT_DIR" ]; then
    mkdir -p "$ARTIFACTS_DIR/bolt"
    find "$BOLT_DIR" -maxdepth 1 -name '*.log' -exec cp {} "$ARTIFACTS_DIR/bolt/" \;
  fi
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT

fetch_release() {
  local destination=$1 branch=$2 revision=$3
  git clone --branch "$branch" https://github.com/DataDog/dd-trace-php/ "$destination"
  git -C "$destination" checkout "$revision"
  git -C "$destination" submodule update --init --recursive
}

# The shared analyzer reads Git metadata from these fixed checkout locations.
# Keep the checkouts until analysis finishes; only BOLT's scratch files are
# removed by this script's exit trap.
CANDIDATE_SRC=/app/candidate
BASELINE_SRC=
fetch_release "$CANDIDATE_SRC" "$CI_COMMIT_REF_NAME" "$CI_COMMIT_SHA"

if [ -z "${BASELINE_BRANCH:-}" ]; then
  BASELINE_BRANCH=$(github-find-merge-into-branch \
    --for-repo="$CI_PROJECT_NAME" --for-pr="$CI_COMMIT_REF_NAME")
fi
printf '%s\n' "$BASELINE_BRANCH" > "$ARTIFACTS_DIR/baseline_branch.txt"
if [ -n "$BASELINE_BRANCH" ]; then
  BASELINE_SHA=$(git -C "$CANDIDATE_SRC" merge-base \
    "origin/$BASELINE_BRANCH" "$CI_COMMIT_SHA")
  BASELINE_SRC=/app/baseline
  fetch_release "$BASELINE_SRC" "$BASELINE_BRANCH" "$BASELINE_SHA"
fi

if [ -z "$BOLT_MODE" ]; then
  if [ -n "$BASELINE_SRC" ]; then BOLT_MODE=align; else BOLT_MODE=plain; fi
fi
export BOLT_MODE
if [ "$BOLT_MODE" = align ] && [ -z "$BASELINE_SRC" ]; then
  echo "BOLT alignment requires a baseline revision; use BOLT_MODE=plain or off for a candidate-only run." >&2
  exit 1
fi
echo "PHP benchmark binary processing: $BOLT_MODE"
printf '%s\n' "$BOLT_MODE" > "$ARTIFACTS_DIR/bolt-mode.txt"

# Older harnesses rebuild separate extensions and would silently discard BOLT's
# output. Both revisions must use the combined-extension harness from #4520.
for SOURCE in "$CANDIDATE_SRC" ${BASELINE_SRC:+"$BASELINE_SRC"}; do
  if ! grep -q DD_TRACE_ASSUME_COMPILED "$SOURCE/benchmark/runall.sh"; then
    echo "The benchmark revisions must include the combined-extension harness from dd-trace-php#4520: $SOURCE" >&2
    exit 1
  fi
done

# Both endpoints must be built and rewritten before either endpoint is timed.
if [ "$BOLT_MODE" != off ]; then
  "$STEPS_DIR/prepare-bolt.sh" "$CANDIDATE_SRC" "$BASELINE_SRC" "$BOLT_DIR"
fi

CODE_SRC="$CANDIDATE_SRC" BASELINE_OR_CANDIDATE=candidate "$STEPS_DIR/run-revision.sh"
if [ -n "$BASELINE_SRC" ]; then
  CODE_SRC="$BASELINE_SRC" BASELINE_OR_CANDIDATE=baseline "$STEPS_DIR/run-revision.sh"
fi
