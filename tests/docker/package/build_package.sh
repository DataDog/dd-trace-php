#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"

function main {
  local -r version=$1 variant=$2 tracer_version=$3
  docker buildx build -o . \
    --build-arg PHP_VERSION=$version --build-arg VARIANT=$variant \
    -f "$SCRIPT_DIR/Dockerfile" "$SCRIPT_DIR/../../.."
}


if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <php_version_no_minor> <variant>" >&2
  exit 1
fi
main "$@"
