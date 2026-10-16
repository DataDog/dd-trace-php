#!/bin/sh
set -eu

tar_bin= root= out= prefix= executable_paths='|'
while [ "$#" -gt 0 ]; do
    case "$1" in
        --tar) tar_bin=$2; shift 2 ;;
        --root) root=$2; shift 2 ;;
        --output) out=$2; shift 2 ;;
        --prefix) prefix=$2; shift 2 ;;
        --executable)
            executable=$2
            case "$executable" in
                /*|*'..'*|*'//'*) echo "unsafe archive executable path: $executable" >&2; exit 2 ;;
            esac
            case "$executable_paths" in *"|$executable|"*) echo "duplicate archive executable path: $executable" >&2; exit 2;; esac
            executable_paths="$executable_paths$executable|"
            shift 2
            ;;
        *) echo "unknown deterministic-tar argument: $1" >&2; exit 2 ;;
    esac
done
[ -n "$tar_bin" ] && [ -n "$root" ] && [ -n "$out" ] && [ -n "$prefix" ]
case "$prefix" in
    . | .. | *[!ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-]* | '')
        echo "invalid archive prefix: $prefix" >&2
        exit 2
        ;;
esac

# Tree-artifact transport does not retain output mode bits. Processwrapper
# also presents each file below a tree artifact as a symlink to the physical
# output tree. The payload rule audits its output before Bazel transports it,
# so dereference those transport links while copying to action scratch space.
# Reconstruct the declared modes before archiving.
stage=$(mktemp -d "${TMPDIR:-/tmp}/deterministic-tar.XXXXXX")
trap 'rm -rf "$stage"' EXIT HUP INT TERM
cp -RL "$root/." "$stage"
stage_link=$(find "$stage" -type l -print -quit)
[ -z "$stage_link" ] || { echo "archive staging contains a symlink: $stage_link" >&2; exit 1; }
find "$stage" -type d -exec chmod 0755 {} \;
find "$stage" -type d -exec chmod u-s,g-s,o-t {} \;
find "$stage" -type f -exec chmod 0644 {} \;
old_ifs=$IFS
IFS='|'
for executable in $executable_paths; do
    [ -z "$executable" ] && continue
    [ -f "$stage/$executable" ] && [ ! -L "$stage/$executable" ] || {
        echo "archive executable path is not a payload regular file: $executable" >&2
        exit 1
    }
    chmod 0755 "$stage/$executable"
done
IFS=$old_ifs
find "$stage" \( -type d -o -type f \) -exec touch -d @0 {} \;
find "$stage" -type d -exec sh -c '
    for path do
        [ "$(stat -c %a "$path")" = 755 ] || { echo "archive directory mode is not 0755: $path" >&2; exit 1; }
    done
' sh {} +

# GNU tar supplies stable lexical ordering, owners, and timestamps. It invokes
# declared gzip through its hermetic PATH, omitting gzip filename/time metadata.
"$tar_bin" --sort=name --format=gnu --mtime=@0 --owner=0 --group=0 --numeric-owner \
    --mode='u+rwX,go+rX,go-w' --transform="flags=r;s,^,$prefix/," -C "$stage" -czf "$out" .
