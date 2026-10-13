#!/usr/bin/env bash
# Alpine SSI ddtrace is combined, but imports common symbols from the
# separately built libdatadog_php.so. Its Rust archive is a private linker input.
set -euo pipefail
shopt -s expand_aliases
source "${BASH_ENV}"

[[ $# -eq 2 ]] || { echo 'usage: build-ssi-combined-alpine.sh output-dir php-api' >&2; exit 1; }
output_dir=$(cd "$1" && pwd)
php_api=$2
root=$(cd "$(dirname "$0")/.." && pwd)
common_library="$output_dir/libdatadog_php.so"
[[ -s "$common_library" ]] || { echo "missing SSI common library: $common_library" >&2; exit 1; }

for variant in nts zts; do
    if [[ $variant == nts ]]; then
        switch-php "$PHP_VERSION"
        suffix=-alpine
    else
        switch-php "$PHP_VERSION-zts"
        suffix=-alpine-zts
    fi
    export CARGO_TARGET_DIR="$root/tmp/ssi-alpine-php-${PHP_VERSION}-${variant}"
    # Build the private PHP-ABI archive and C extension together with ThinLTO.
    # The helper also restores weak PHP imports and load-tests with the common
    # DSO preloaded, without adding a DT_NEEDED edge.
    "$root/tooling/bin/build-xlang-lto" ssi-combined "$output_dir"
    mv "$output_dir/ddtrace.so" "$output_dir/ddtrace-${php_api}${suffix}.so"
done
