#!/usr/bin/env bash

set -ex

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
source "$SCRIPT_DIR"/../lib.sh

function main {
  local -r version=$1 variant=$2
  if ! image_exists datadog/dd-appsec-php-ci:php-$version-$variant; then
    "$SCRIPT_DIR"/../php/build_image.sh $version $variant
  fi

  docker build -t dd-appsec-php-extension:$version-$variant \
    --build-arg PHP_VERSION=$version --build-arg VARIANT=$variant \
    -f "$SCRIPT_DIR/Dockerfile" "$SCRIPT_DIR/../../.."
}

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <php_version_no_minor> <variant>" >&2
  exit 1
fi

main "$@"
