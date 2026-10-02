#!/bin/sh
# Mutate a valid manifest to prove the inventory verifier rejects bad input.
set -eu

checker=${1:?checker path required}
manifest=${2:?matrix manifest required}
tmp=$(mktemp -d "${TMPDIR:-/tmp}/matrix-validation.XXXXXX")
trap 'rm -rf "$tmp"' EXIT HUP INT TERM

reject() {
    name=$1 file=$2
    if "$checker" "$file" >/dev/null 2>&1; then
        echo "matrix negative fixture unexpectedly passed: $name" >&2
        exit 1
    fi
}

jq 'del(.records[0])' "$manifest" > "$tmp/dropped.json"
reject dropped-row "$tmp/dropped.json"
jq '.records[1].name = .records[0].name' "$manifest" > "$tmp/duplicate.json"
reject duplicate-row "$tmp/duplicate.json"
jq '.records[0].expected_artifacts |= map(select(. != "sdk/bin/php-config"))' "$manifest" > "$tmp/artifact.json"
reject missing-required-artifact "$tmp/artifact.json"
jq '.records[0].source_sha256 = "not-a-sha"' "$manifest" > "$tmp/source.json"
reject wrong-source-lock "$tmp/source.json"
jq '.records[0].api = 0' "$manifest" > "$tmp/api.json"
reject wrong-php-api "$tmp/api.json"
jq '(.records[] | select(.runtime_profile == "bookworm" and .minor == "7.4" and (.name | endswith("_shared"))) | .historical_shared_extensions = ["ffi", "mbstring", "pcntl"])' "$manifest" > "$tmp/shared.json"
reject wrong-shared-extension-mode "$tmp/shared.json"
