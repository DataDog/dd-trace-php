#!/bin/bash

cd "$(dirname "$0")"

LOCK=/tmp/randomized-tests-lock-dir
while true; do
  if mkdir $LOCK 2>/dev/null; then
    trap "rmdir $LOCK" EXIT
    if [[ ${1:-} == shutdown ]]; then
      # check for running tests; in that case don't shutdown
      if ! docker network ls | grep -q randomized-; then
        sleep 1
        # prevent race conditions
        if ! docker network ls | grep -q randomized-; then
          docker-compose down
        fi
      fi
    else
      # while to make absolutely sure it's running
      while ! docker network ls | grep -q randomized_tests_baseservices || { sleep 1; ! docker network ls | grep -q randomized_tests_baseservices }; do
        docker-compose up -d
      done
    fi
    exit 0
  fi
  sleep 1
done