#!/bin/bash

if [ -z "$TMPDIR" ]; then
    export TMPDIR="/tmp"
fi

set -eu

# The branch being tested.
head="$1"

# The baseline to compare against.
target_branch="$2"

merge_base=$(git merge-base "$head" "$target_branch")

git checkout "$head"
cp -v bench.sh "$TMPDIR/bench.sh"

bash "$TMPDIR/bench.sh"
mv -v trigger-*.txt "$TMPDIR/"

git checkout "$merge_base"
bash "$TMPDIR/bench.sh"

git checkout "$head"
