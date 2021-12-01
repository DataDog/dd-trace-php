#!/usr/bin/env bash

set -ex

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
source "$SCRIPT_DIR"/../lib.sh

function main {
  cd "$SCRIPT_DIR/.."
  if ! image_exists toolchain; then
    docker-compose build toolchain
  fi
  docker-compose build hunter-cache
}

main "$@"
