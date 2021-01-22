#!/usr/bin/env bash

set -e

bash /scripts/prepare.sh

echo "Starting load"
vegeta -cpus=1 attack -format=http -targets=./request-targets.txt -duration=${DURATION:-30s} -keepalive=true -max-workers=10 -workers=10 -rate=0 | tee results.bin | vegeta report --type=json --output=/results/results.json
echo "Done loading"
