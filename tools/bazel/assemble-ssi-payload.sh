#!/bin/sh
set -eu

out= out_physical= version_file= required_paths= seen='|' executable_paths='|'
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output)
            out=$2
            [ -n "$out" ] || { echo "SSI output path is empty" >&2; exit 2; }
            [ ! -L "$out" ] || { echo "SSI output path must not be a symlink: $out" >&2; exit 2; }
            [ ! -e "$out" ] || [ -d "$out" ] || { echo "SSI output path is not a directory: $out" >&2; exit 2; }
            mkdir -p "$out"
            out_physical=$(cd "$out" && pwd -P)
            shift 2
            ;;
        --version-file) version_file=$2; shift 2 ;;
        --copy)
            source=$2 destination=$3
            [ -n "$out_physical" ] || { echo "--output must precede --copy" >&2; exit 2; }
            case "$destination" in
                /*|*'..'*|*'//'*) echo "unsafe SSI destination: $destination" >&2; exit 2 ;;
            esac
            [ "$destination" != version ] || { echo "SSI version is written only from --version-file" >&2; exit 2; }
            # Bazel materializes a declared action input through an execroot
            # symlink. Follow only that command-line input: nested source
            # links remain forbidden, and the published tree is audited below.
            nested_link=$(find -H "$source" -type l -print -quit)
            [ -z "$nested_link" ] || { echo "SSI payload source contains a symlink: $nested_link" >&2; exit 2; }
            case "$seen" in *"|$destination|"*|*"|$destination/"*) echo "duplicate or nested SSI destination: $destination" >&2; exit 2;; esac
            parent=$destination
            while :; do
                case "$parent" in */*) parent=${parent%/*};; *) break;; esac
                [ ! -L "$out/$parent" ] || { echo "SSI destination traverses symlink: $destination" >&2; exit 2; }
                [ ! -e "$out/$parent" ] || [ -d "$out/$parent" ] || { echo "SSI destination parent is not a directory: $destination" >&2; exit 2; }
                case "$seen" in *"|$parent|"*) echo "nested SSI destination: $destination" >&2; exit 2;; esac
            done
            [ ! -e "$out/$destination" ] && [ ! -L "$out/$destination" ] || { echo "SSI destination already exists: $destination" >&2; exit 2; }
            parent=${destination%/*}
            [ "$parent" = "$destination" ] && parent=.
            mkdir -p "$out/$parent"
            destination_parent=$(cd "$out/$parent" && pwd -P)
            case "$destination_parent" in
                "$out_physical"|"$out_physical"/*) ;;
                *) echo "SSI destination escapes output path: $destination" >&2; exit 2 ;;
            esac
            cp -pRH "$source" "$out/$destination"
            seen="$seen$destination|"
            shift 3
            ;;
        --require)
            required=$2
            case "$required" in
                /*|*'..'*|*'//'*) echo "unsafe SSI required path: $required" >&2; exit 2 ;;
            esac
            required_paths="$required_paths $required"
            shift 2
            ;;
        --executable)
            executable=$2
            case "$executable" in
                /*|*'..'*|*'//'*) echo "unsafe SSI executable path: $executable" >&2; exit 2 ;;
            esac
            case "$executable_paths" in *"|$executable|"*) echo "duplicate SSI executable path: $executable" >&2; exit 2;; esac
            executable_paths="$executable_paths$executable|"
            shift 2
            ;;
        *) echo "unknown SSI payload argument: $1" >&2; exit 2 ;;
    esac
done
[ -n "$out" ] && [ -n "$out_physical" ] && [ -n "$version_file" ] && [ -r "$version_file" ]

IFS= read -r version < "$version_file" || true
case "$version" in
    *+*) version="${version%%+*}-${version#*+}" ;;
esac
case "$version" in
    ''|*[!ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._+-]*)
        echo "invalid SSI version" >&2
        exit 2
        ;;
esac
printf '%s\n' "$version" > "$out/version"
for required in $required_paths; do
    [ ! -L "$out/$required" ] && [ -e "$out/$required" ] || {
        echo "SSI payload required path was not assembled safely: $required" >&2
        exit 1
    }
done
payload_link=$(find "$out" -type l -print -quit)
[ -z "$payload_link" ] || { echo "SSI payload contains a symlink: $payload_link" >&2; exit 1; }

# Sources can arrive with arbitrary owner modes and mtimes.  Preserve only
# whether a regular file is executable; payload directories and all other
# files have one canonical mode, and every published entry has the epoch time.
find "$out" -type d -exec chmod 0755 {} \;
find "$out" -type d -exec chmod u-s,g-s,o-t {} \;
find "$out" -type f -exec chmod 0644 {} \;
old_ifs=$IFS
IFS='|'
for executable in $executable_paths; do
    [ -z "$executable" ] && continue
    [ -f "$out/$executable" ] && [ ! -L "$out/$executable" ] || {
        echo "SSI executable path is not a copied regular file: $executable" >&2
        exit 1
    }
    chmod 0755 "$out/$executable"
done
IFS=$old_ifs
find "$out" \( -type d -o -type f \) -exec touch -d @0 {} \;
find "$out" -type d -exec sh -c '
    for path do
        [ "$(stat -c %a "$path")" = 755 ] || { echo "SSI directory mode is not 0755: $path" >&2; exit 1; }
    done
' sh {} +
