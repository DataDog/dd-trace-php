#!/bin/sh
# Re-evaluate one OCI SDK repository with the network namespace disabled.
set -eu

if [ "$#" -ne 5 ]; then
    echo "usage: $0 BB OUTPUT_USER_ROOT REPOSITORY_CACHE REPO_CONTENTS_CACHE_OR_DASH TARGET" >&2
    exit 2
fi

bb=$1
output_user_root=$2
repository_cache=$3
repo_contents_cache=$4
target=$5
workspace=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd -P)
build_root=$(CDPATH= cd -- "$(dirname -- "$output_user_root")" && pwd -P)

if [ "$repo_contents_cache" = - ]; then
    exec bwrap \
        --unshare-net \
        --ro-bind / / \
        --dev-bind /dev /dev \
        --proc /proc \
        --bind /tmp /tmp \
        --bind "$workspace" "$workspace" \
        --bind "$build_root" "$build_root" \
        --chdir "$workspace" \
        "$bb" \
        --batch \
        --output_user_root="$output_user_root" \
        query \
        --repository_cache="$repository_cache" \
        --repo_contents_cache= \
        "$target"
fi

exec bwrap \
    --unshare-net \
    --ro-bind / / \
    --dev-bind /dev /dev \
    --proc /proc \
    --bind /tmp /tmp \
    --bind "$workspace" "$workspace" \
    --bind "$build_root" "$build_root" \
    --bind "$repo_contents_cache" "$repo_contents_cache" \
    --chdir "$workspace" \
    "$bb" \
    --batch \
    --output_user_root="$output_user_root" \
    query \
    --repository_cache="$repository_cache" \
    --repo_contents_cache="$repo_contents_cache" \
    "$target"
