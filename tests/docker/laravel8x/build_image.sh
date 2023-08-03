#!/usr/bin/env bash

set -ex

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
source "$SCRIPT_DIR"/../lib.sh

function main {
  local -r version=$1 variant=$2 tracer_version=$3
    if ! image_exists dd-appsec-php-apache2-fpm:$version-$variant-tracer$tracer_version; then
    "$SCRIPT_DIR"/../apache2-fpm/build_image.sh $version $variant $tracer_version
  fi

  docker build -t dd-appsec-php-laravel8x:$version-$variant-tracer$tracer_version \
    --build-arg PHP_VERSION=$version --build-arg VARIANT=$variant \
    --build-arg TRACER_VERSION=$tracer_version \
    -f "$SCRIPT_DIR/Dockerfile" "$SCRIPT_DIR/../../.."
}

if [[ $# -ne 3 ]]; then
  echo "Usage: $0 <php_version_no_minor> <variant> <tracer_version>" >&2
  exit 1
fi

main "$@"
