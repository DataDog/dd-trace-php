#!/usr/bin/env bash

set -ex

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
source "$SCRIPT_DIR"/../lib.sh

function main {
  local -r version=$1 variant=$2
  cd "$SCRIPT_DIR/.."
  if ! image_exists datadog/dd-appsec-php-ci:toolchain; then
    docker-compose build toolchain
  fi
  docker-compose build php-$version-$variant
}

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <php_version_no_minor> <variant>" >&2
  exit 1
fi

main "$@"
