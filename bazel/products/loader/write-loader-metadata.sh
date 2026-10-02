set -eu

version_file=$1
output=$2
architecture=$3
image_digest=$4
libc=$5
php_api=$6
php_sdk_configuration=$7
php_sdk_version=$8
loader_version=$(cat "$version_file")

python3 - "$output" "$architecture" "$image_digest" "$libc" "$loader_version" "$php_api" "$php_sdk_configuration" "$php_sdk_version" <<'PY'
import json
import sys

output, architecture, image_digest, libc, loader_version, php_api, configuration, php_version = sys.argv[1:]
value = {
    "architecture": architecture,
    "image_digest": image_digest,
    "libc": libc,
    "loader_version": loader_version,
    "php_api": int(php_api),
    "php_sdk_configuration": configuration,
    "php_sdk_version": php_version,
    "universal_php_max": "8.5",
    "universal_php_min": "7.0",
}
with open(output, "w", encoding="utf-8", newline="\n") as stream:
    json.dump(value, stream, sort_keys=True, separators=(",", ":"))
    stream.write("\n")
PY
