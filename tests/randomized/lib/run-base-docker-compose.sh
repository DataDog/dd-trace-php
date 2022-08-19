#!/bin/bash

cd "$(dirname "$0")"

LOCK=/tmp/randomized-tests-lock-dir
while true; do
  if mkdir $LOCK 2>/dev/null; then
    trap "rmdir $LOCK" EXIT
    if [[ ${1:-} == shutdown ]]; then
      if ! docker network ls | grep -q randomized-; then
        docker-compose down
      fi
    else
      if ! docker network ls | grep -q randomized_tests_baseservices; then
        docker-compose up -d
      fi
    fi
    exit 0
  fi
  sleep 1
done