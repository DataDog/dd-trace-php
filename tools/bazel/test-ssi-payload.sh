#!/bin/sh
# Focused fixtures for path safety and version normalization in SSI assembly.
set -eu

assembler=${1:?assembler path required}
tar_runner=${2:?deterministic-tar path required}
tar_bin=${3:?GNU tar path required}
tmp=$(mktemp -d "${TMPDIR:-/tmp}/ssi-payload.XXXXXX")
trap 'rm -rf "$tmp"' EXIT HUP INT TERM

mkdir -p "$tmp/src/lib" "$tmp/out"
printf 'loader\n' > "$tmp/src/lib/tool"
printf '1.2.3+build.7\n' > "$tmp/version"
chmod 751 "$tmp/src/lib/tool"
chmod 2755 "$tmp/src/lib"
touch -d @135 "$tmp/src" "$tmp/src/lib" "$tmp/src/lib/tool"
"$assembler" --output "$tmp/out" --version-file "$tmp/version" \
    --copy "$tmp/src" payload --executable payload/lib/tool --require payload/lib/tool --require version
[ "$(cat "$tmp/out/version")" = '1.2.3-build.7' ]
[ "$(cat "$tmp/out/payload/lib/tool")" = loader ]
[ "$(stat -c %a "$tmp/out")" = 755 ]
[ "$(stat -c %a "$tmp/out/payload/lib")" = 755 ]
[ "$(stat -c %a "$tmp/out/payload/lib/tool")" = 755 ]
[ "$(stat -c %a "$tmp/out/version")" = 644 ]
[ "$(stat -c %Y "$tmp/out/payload/lib/tool")" = 0 ]
[ "$(stat -c %Y "$tmp/out/version")" = 0 ]
"$tar_runner" --tar "$tar_bin" --root "$tmp/out" --output "$tmp/one.tar.gz" --prefix fixture --executable payload/lib/tool
"$tar_runner" --tar "$tar_bin" --root "$tmp/out" --output "$tmp/two.tar.gz" --prefix fixture --executable payload/lib/tool
cmp "$tmp/one.tar.gz" "$tmp/two.tar.gz"
tar -xzf "$tmp/one.tar.gz" -C "$tmp"
[ "$(stat -c %a "$tmp/fixture/payload/lib/tool")" = 755 ]
[ "$(stat -c %a "$tmp/fixture/version")" = 644 ]
[ "$(stat -c %a "$tmp/fixture")" = 755 ]
[ "$(stat -c %a "$tmp/fixture/payload/lib")" = 755 ]

if "$assembler" --output "$tmp/bad" --version-file "$tmp/version" \
    --copy "$tmp/src" loader --copy "$tmp/src" loader/nested; then
    echo "nested SSI destination fixture unexpectedly passed" >&2
    exit 1
fi
ln -s "$tmp/src/lib/tool" "$tmp/link"
"$assembler" --output "$tmp/dereferenced-link" --version-file "$tmp/version" --copy "$tmp/link" loader --require loader
[ ! -L "$tmp/dereferenced-link/loader" ]
[ "$(cat "$tmp/dereferenced-link/loader")" = loader ]
mkdir -p "$tmp/nested-source"
ln -s "$tmp/src/lib/tool" "$tmp/nested-source/link"
if "$assembler" --output "$tmp/bad-link" --version-file "$tmp/version" --copy "$tmp/nested-source" payload; then
    echo "nested symlink SSI source fixture unexpectedly passed" >&2
    exit 1
fi
mkdir -p "$tmp/escape"
ln -s "$tmp/escape" "$tmp/output-link"
if "$assembler" --output "$tmp/output-link" --version-file "$tmp/version" --copy "$tmp/src" payload; then
    echo "symlink SSI output fixture unexpectedly passed" >&2
    exit 1
fi
if "$tar_runner" --tar "$tar_bin" --root "$tmp/out" --output "$tmp/dot.tar.gz" --prefix .; then
    echo "dot archive prefix fixture unexpectedly passed" >&2
    exit 1
fi
if "$tar_runner" --tar "$tar_bin" --root "$tmp/out" --output "$tmp/bad.tar.gz" --prefix ../unsafe; then
    echo "unsafe archive prefix fixture unexpectedly passed" >&2
    exit 1
fi
