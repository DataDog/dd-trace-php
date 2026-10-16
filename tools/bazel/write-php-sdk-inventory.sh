#!/bin/sh
set -eu

execroot=$PWD
absolute() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *) printf '%s/%s\n' "$execroot" "$1" ;;
    esac
}

spec=$(absolute "$1")
output=$(absolute "$2")
shift 2
while test "$#" -gt 0; do
    name=$1
    sdk=$(absolute "$2")
    shift 2
    for relative in \
        bin/php-config \
        include/php/Zend/zend.h \
        include/php/main/php.h \
        include/php/main/php_config.h \
        include/php/main/php_version.h \
        metadata/import.lock.json \
        metadata/observed.json \
        metadata/provenance.json
    do
        test -f "$sdk/$relative" || {
            echo "PHP SDK inventory is missing $name/$relative" >&2
            exit 1
        }
    done
done
cp "$spec" "$output"
chmod 0644 "$output"
touch -h -d @0 "$output"
