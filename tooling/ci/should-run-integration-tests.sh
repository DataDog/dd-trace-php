#!/bin/bash

commit_message=$(git log -1 --pretty=%B ${CIRCLE_SHA1})

if echo "$commit_message" | grep -q "#force-test-integrations"; then
    echo "Force test integrations flag detected in git commit message"
    exit 0
fi

git fetch origin master:master
changed_files=$(git --no-pager diff --name-only origin/master..${CIRCLE_SHA1})

echo "Changed files:"
echo "$changed_files"

test_dirs="
components
components-rs
ext
src
tests/Integrations
"

for dir in $test_dirs; do
    if echo "$changed_files" | grep -qE "^$dir/"; then
        exit 0
    fi
done

exit 1
