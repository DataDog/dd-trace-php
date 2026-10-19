#!/bin/sh
# This program is launched by the declared static execution shell.  PHP is the
# SDK's uninstrumented host binary; target PHP and Composer never execute here.
set -eu

preflight=$1
shift
. "$preflight"

php=
php_loader=
php_library_path=
tokenizer=
classpreloader=
api_config=
tracer_config=
opentelemetry_config=
openfeature_config=
api_output=
tracer_output=
opentelemetry_output=
openfeature_output=

while [ "$#" -gt 0 ]; do
    case "$1" in
        --php) php=$2 ;;
        --php-loader) php_loader=$2 ;;
        --php-library-path) php_library_path=$2 ;;
        --tokenizer) tokenizer=$2 ;;
        --classpreloader) classpreloader=$2 ;;
        --api-config) api_config=$2 ;;
        --tracer-config) tracer_config=$2 ;;
        --opentelemetry-config) opentelemetry_config=$2 ;;
        --openfeature-config) openfeature_config=$2 ;;
        --api-output) api_output=$2 ;;
        --tracer-output) tracer_output=$2 ;;
        --opentelemetry-output) opentelemetry_output=$2 ;;
        --openfeature-output) openfeature_output=$2 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
    shift 2
done

for required in "$php" "$php_loader" "$php_library_path" "$classpreloader" "$api_config" "$tracer_config" "$opentelemetry_config" "$openfeature_config" "$api_output" "$tracer_output" "$opentelemetry_output" "$openfeature_output"; do
    test -n "$required"
done

preflight_host_php "$php_loader" "$php_library_path" "$php" "$php_library_path"

generate() {
    config=$1
    output=$2
    if [ -n "$tokenizer" ]; then
        "$php_loader" --inhibit-cache --library-path "$php_library_path" "$php" -n -d "extension=$tokenizer" "$classpreloader" compile --config="$config" --output="$output"
    else
        "$php_loader" --inhibit-cache --library-path "$php_library_path" "$php" -n "$classpreloader" compile --config="$config" --output="$output"
    fi
    # Preserve Makefile generation's normalization expression and ordering.
    sed -i "s/'[^']\\+bridge\\/\\.\\./__DIR__ . '\\/../g;s/\\s*\\(^\\|\\s\\)\\/\\/.*//g;s/\\/\\*\\([^*]\\|\\*[^/]\\)*\\*\\///g;/\\/\\*/,/\\*\\//d;/^\\s*$/d" "$output"
}

generate "$api_config" "$api_output"
generate "$tracer_config" "$tracer_output"
generate "$opentelemetry_config" "$opentelemetry_output"
generate "$openfeature_config" "$openfeature_output"
