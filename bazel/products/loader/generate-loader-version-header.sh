set -eu

version_file=$1
output=$2
version=$(cat "$version_file")

case "$version" in
    *[!0-9A-Za-z._+-]*|'')
        echo "invalid loader version: $version" >&2
        exit 1
        ;;
esac

printf '#ifndef PHP_DD_LIBRARY_LOADER_VERSION\n#define PHP_DD_LIBRARY_LOADER_VERSION "%s"\n#endif\n' "$version" > "$output"
