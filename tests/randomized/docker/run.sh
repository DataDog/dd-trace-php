#!/usr/bin/env bash

set -e

bash /scripts/enable-coredump.sh
bash /scripts/prepare.sh

echo "Starting web load"
vegeta -cpus=1 attack -format=http -targets=/vegeta-request-targets.txt -duration=${DURATION:-30s} -keepalive=false -max-workers=10 -workers=10 -rate=0 | tee results.bin | vegeta report --type=json --output=/results/results.json
echo "Done web load"

echo "Starting CLI load"
sh /cli-runner.sh
echo "Done CLI load"
