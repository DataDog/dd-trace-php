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
    archive="$CARGO_TARGET_DIR/ddtrace-php-abi.a"
    "$root/tooling/bin/build-ddtrace-php-abi-archive" "$archive"
    SSI_COMMON_LIBRARY="$common_library" SSI_EXTENSION_ARCHIVE="$archive" \
        "$root/.gitlab/build-profiler.sh" "$output_dir" active ssi-combined "ddtrace-${php_api}${suffix}.so"
    # SSI imports the common symbols through the loader's preload, not DT_NEEDED.
    LD_PRELOAD="$common_library${LD_PRELOAD:+:$LD_PRELOAD}" \
        php -n -d "extension=$output_dir/ddtrace-${php_api}${suffix}.so" \
        -r 'if (!extension_loaded("ddtrace")) exit(1);'
done
