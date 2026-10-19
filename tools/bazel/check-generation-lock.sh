#!/bin/sh
# Validate the lock inputs before repository setup downloads the vendor tree.
set -eu

lock=${1:-tooling/generation/composer.lock}
manifest=${2:-bazel/dependencies/manifest.bzl}

test "$(jq -r '."content-hash"' "$lock")" = 1173ca1a1a2703688cdfb4e8423af311
test "$(jq -r '."packages-dev" | length' "$lock")" = 8
test "$(jq -r '[."packages-dev"[].name] | unique | length' "$lock")" = 8

while IFS=' ' read -r package version reference; do
    actual=$(jq -r --arg package "$package" '."packages-dev"[] | select(.name == $package) | .version' "$lock")
    test "$actual" = "$version"
    actual=$(jq -r --arg package "$package" '."packages-dev"[] | select(.name == $package) | .source.reference' "$lock")
    test "$actual" = "$reference"
    # The setup manifest uses immutable codeload URLs containing this source
    # revision and a separately verified SHA-256 archive digest.
    grep -F "$reference" "$manifest" >/dev/null
done <<'EOF'
classpreloader/classpreloader 4.2.0 af9284543aedb45ed58359374918141c0ac7ae34
classpreloader/console 3.1.0 8475b97e5f69513ff9d68387d4da83a37afb6b71
nikic/php-parser v4.19.5 51bd93cc741b7fc3d63d20b6bdcd99fdaa359837
psr/log 1.1.4 d49695b909c3b7628b6289db5479a1c204601f11
symfony/console v3.4.47 a10b1da6fc93080c180bba7219b5ff5b7518fe81
symfony/debug v3.4.47 ab42889de57fdfcfcc0759ab102e2fd4ea72dcae
symfony/polyfill-ctype v1.27.0 5bbc823adecdae860bb64756d639ecfec17b050a
symfony/polyfill-mbstring v1.27.0 8ad114f6b39e2c98a8b0e3bd907732c207c2b534
EOF

# Exactly eight generator archives must be checksum locks, not only source
# revision references.  This deliberately rejects a missing or malformed hash.
test "$(sed -n '/^GENERATION_COMPOSER_PACKAGES = {/,/^}/p' "$manifest" | grep -Ec 'sha256 = "[0-9a-f]{64}"')" = 8

printf '%s\n' 'generation Composer lock and archive checksums are complete'
