#!/bin/sh
set -eu

execroot=$PWD
absolute() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *) printf '%s/%s\n' "$execroot" "$1" ;;
    esac
}

HERMETIC_TOOLS_ROOT=$(absolute "$HERMETIC_TOOLS_ROOT")
export HERMETIC_TOOLS_ROOT
absolute_path=
old_ifs=$IFS
IFS=:
for entry in ${PATH:-}; do
    case "$entry" in
        /*) resolved=$entry ;;
        *) resolved=$execroot/$entry ;;
    esac
    absolute_path=${absolute_path:+$absolute_path:}$resolved
done
IFS=$old_ifs
PATH=$absolute_path
export PATH

header_map=$(absolute "$1")
sdk=$(absolute "$2")
observed=$(absolute "$3")
lock=$(absolute "$4")
provenance=$(absolute "$5")
derive_debug=$6
unavailable_image_digest=$7
effective_observed=$(absolute "$8")
expected_version=$9
expected_api=${10}
expected_zend_module_api=${11}
expected_zend_extension_api=${12}
expected_arch=${13}
expected_debug=${14}
expected_zts=${15}
expected_asan=${16}
expected_image_family=${17}
expected_profile=${18}
expected_image_digest=${19}
expected_target_libc=${20}
expected_target_triple=${21}
php_config=$(absolute "${22}")
metadata_validator=$(absolute "${23}")

python3 "$metadata_validator" \
    "$observed" "$lock" "$expected_version" "$expected_api" \
    "$expected_zend_module_api" "$expected_zend_extension_api" \
    "$expected_arch" "$expected_debug" "$expected_zts" "$expected_asan" \
    "$expected_image_family" "$expected_profile" "$expected_image_digest" \
    "$expected_target_libc" "$expected_target_triple"
rm -rf "$sdk"
mkdir -p "$sdk/include/php" "$sdk/metadata"
while IFS="$(printf '\t')" read -r input relative; do
    test -n "$input" || continue
    input=$(absolute "$input")
    case "$relative" in
        include/php/*) ;;
        *) echo "invalid PHP SDK header path: $relative" >&2; exit 1 ;;
    esac
    mkdir -p "$sdk/$(dirname "$relative")"
    cp "$input" "$sdk/$relative"
done < "$header_map"
if test "$derive_debug" = 1; then
    config=$sdk/include/php/main/php_config.h
    grep -Eq '^#define ZEND_DEBUG 0$' "$config"
    sed -i 's/^#define ZEND_DEBUG 0$/#define ZEND_DEBUG 1/' "$config"
    grep -Eq '^#define ZEND_DEBUG 1$' "$config"
fi
cp "$observed" "$effective_observed"
if test "$derive_debug" = 1; then
    grep -Fq '"debug":false' "$effective_observed"
    sed -i 's/"debug":false/"debug":true/' "$effective_observed"
    grep -Fq '"debug":true' "$effective_observed"
fi
if test "$derive_debug" = 1; then
    cp "$observed" "$sdk/metadata/base-observed.json"
    printf '%s\n' "{\"base_observed\":\"base-observed.json\",\"transform\":\"ZEND_DEBUG=0 to ZEND_DEBUG=1\",\"unavailable_image_digest\":\"$unavailable_image_digest\"}" > "$sdk/metadata/derivation.json"
else
    :
fi
cp "$effective_observed" "$sdk/metadata/observed.json"
sdk_basename=$(basename "$sdk")
vernum=$(sed -n 's/^#define PHP_VERSION_ID //p' "$sdk/include/php/main/php_version.h")
case "$vernum" in
    ''|*[!0-9]*) echo "invalid PHP_VERSION_ID in materialized SDK: $vernum" >&2; exit 1 ;;
esac
cat > "$php_config" <<EOF
#!/bin/sh
tool_dir=\$(CDPATH= cd -- "\$(dirname -- "\$0")" && pwd)
if test "\$(basename -- "\$tool_dir")" = bin; then
    prefix=\$(CDPATH= cd -- "\$tool_dir/.." && pwd)
else
    prefix=\$(CDPATH= cd -- "\$tool_dir/../$sdk_basename" && pwd)
fi
case "\${1:-}" in
    --includes) printf '%s\\n' "-I\$prefix/include/php -I\$prefix/include/php/main -I\$prefix/include/php/TSRM -I\$prefix/include/php/Zend -I\$prefix/include/php/ext -I\$prefix/include/php/ext/date/lib" ;;
    --include-dir) printf '%s\\n' "\$prefix/include/php" ;;
    --phpapi) printf '%s\\n' '$expected_api' ;;
    --prefix) printf '%s\\n' "\$prefix" ;;
    --vernum) printf '%s\\n' '$vernum' ;;
    --version) printf '%s\\n' '$expected_version' ;;
    --configure-options) echo 'configure options are unavailable in a header-only OCI SDK; consume PhpToolchainInfo ABI fields' >&2; exit 2 ;;
    *) echo "usage: php-config {--includes|--include-dir|--phpapi|--prefix|--vernum|--version}" >&2; exit 2 ;;
esac
EOF
chmod 0755 "$php_config"
mkdir -p "$sdk/bin"
cp "$php_config" "$sdk/bin/php-config"
cp "$lock" "$sdk/metadata/import.lock.json"
cp "$provenance" "$sdk/metadata/provenance.json"
find "$sdk" -type d -exec chmod 0755 {} +
find "$sdk" -type f -exec chmod 0644 {} +
chmod 0755 "$sdk/bin/php-config"
find "$sdk" -exec touch -h -d @0 {} +
chmod 0644 "$effective_observed"
touch -h -d @0 "$effective_observed"
touch -h -d @0 "$php_config"
