#!/usr/bin/env bash

set -ex

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
source "$SCRIPT_DIR"/../lib.sh

function main {
  if ! image_exists datadog/dd-appsec-php-ci:hunter-cache; then
    "$SCRIPT_DIR"/../hunter-cache/build_image.sh
  fi

  docker build -t dd-appsec-php-helper \
    -f "$SCRIPT_DIR/Dockerfile" "$SCRIPT_DIR/../../.."
}

main "$@"
