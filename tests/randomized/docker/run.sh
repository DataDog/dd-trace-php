#!/usr/bin/env bash

set -e

. /scripts/enable-coredump.sh

ret=0
bash /scripts/prepare.sh || ret=$?
if [[ $ret -ne 0 ]]; then
  # handle transient prepare failures
  echo $ret > /results/prepare-error
  exit $ret
fi

echo "Starting web load"
vegeta -cpus=2 attack -format=http -targets=/vegeta-request-targets.txt -duration=${DURATION:-30s} -keepalive=false -max-workers=10 -workers=10 -rate=100 | tee results.bin | vegeta report --type=json --output=/results/results.json
echo "Done web load"

echo "Starting CLI load"
if ldd $(which php) 2>/dev/null | grep -q libasan; then
  sh /cli-runner.sh
else
  strace -ttfs 200 bash -c 'sh /cli-runner.sh 2>&3' 3>&2 2>/results/php-cli.strace
fi
echo "Done CLI load"
