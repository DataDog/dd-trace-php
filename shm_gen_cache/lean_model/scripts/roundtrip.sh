#!/bin/sh
set -eu

model_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$model_dir"

node scripts/check_claims.mjs --all-contexts
node scripts/run_claimcheck.mjs
node scripts/report_claims.mjs --fail-on-open
