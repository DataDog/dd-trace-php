#!/usr/bin/env bash
# Build each Alpine SSI extension with its matching common DSO. PHP 7.0 is
# tracer-only; supported PHP versions also build a private PHP-ABI Rust archive.
set -euo pipefail
shopt -s expand_aliases
source "${BASH_ENV}"

[[ $# -eq 2 ]] || { echo 'usage: build-ssi-combined-alpine.sh output-dir php-api' >&2; exit 1; }
output_dir=$(cd "$1" && pwd)
php_api=$2
root=$(cd "$(dirname "$0")/.." && pwd)

for variant in nts zts; do
    if [[ $variant == nts ]]; then
        switch-php "$PHP_VERSION"
        suffix=-alpine
    else
        switch-php "$PHP_VERSION-zts"
        suffix=-alpine-zts
    fi
    # Keep the Cargo target path identical across PHP versions and ABIs.
    export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$root/tmp/ssi-rust-target}"
    # Build the private PHP-ABI archive and C extension together with ThinLTO.
    # The helper also restores weak PHP imports and load-tests with the common
    # DSO preloaded, without adding a DT_NEEDED edge.
    "$root/tooling/bin/build-xlang-lto" ssi-combined "$output_dir"
    mv "$output_dir/ddtrace.so" "$output_dir/ddtrace-${php_api}${suffix}.so"
done
