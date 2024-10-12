#!/bin/sh

set -e

main() {
  local patchelf=$1
  local readelf=$2
  local target=$3

  "$patchelf" $(
    "$readelf" -d "$target" 2>/dev/null | grep libc\\. | grep NEEDED | \
      awk -F'[][]' '{print "--remove-needed " $2;}' | xargs
     ) \
     "$target"
}

main "$@"
