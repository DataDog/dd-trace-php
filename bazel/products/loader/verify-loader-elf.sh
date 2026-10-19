set -eu

objdump=$1
nm=$2
objcopy=$3
binary=$4
debug=$5
architecture=$6
libc=$7
soname=$8
version_file=$9
debuglink_validator=${10}
marker=${11}
version=$(cat "$version_file")

scratch=${TMPDIR:-/tmp}/loader-elf.$$
mkdir -p "$scratch"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM

"$objdump" -f "$binary" > "$scratch/header"
grep -F "architecture: $architecture" "$scratch/header" >/dev/null

"$objdump" -p "$binary" > "$scratch/private"
actual_soname=$(sed -n 's/^ *SONAME  *//p' "$scratch/private")
if [ "$actual_soname" != "$soname" ]; then
    echo "loader SONAME $actual_soname does not equal $soname" >&2
    exit 1
fi
if grep -E 'RPATH|RUNPATH' "$scratch/private" >/dev/null; then
    echo "loader must not contain RPATH or RUNPATH" >&2
    exit 1
fi

case "$libc" in
    glibc)
        needed_allow='^(libc\.so\.6|libdl\.so\.2|libm\.so\.6|libpthread\.so\.0|librt\.so\.1)$'
        required_libc='libc.so.6'
        ;;
    musl)
        case "$architecture" in
            x86_64) required_libc='libc.musl-x86_64.so.1' ;;
            aarch64) required_libc='libc.musl-aarch64.so.1' ;;
            *) echo "unsupported musl architecture: $architecture" >&2; exit 1 ;;
        esac
        needed_allow="^${required_libc}$"
        ;;
    *)
        echo "unknown libc: $libc" >&2
        exit 1
        ;;
esac
sed -n 's/^ *NEEDED  *//p' "$scratch/private" > "$scratch/needed"
grep -Fx "$required_libc" "$scratch/needed" >/dev/null || {
    echo "loader does not declare required $libc dependency $required_libc" >&2
    exit 1
}
while read -r library; do
    if ! printf '%s\n' "$library" | grep -E "$needed_allow" >/dev/null; then
        echo "unexpected $libc loader dependency: $library" >&2
        exit 1
    fi
done < "$scratch/needed"
if [ "$libc" = glibc ]; then
    python3 - "$scratch/private" <<'PY'
import re
import sys
text = open(sys.argv[1], encoding="utf-8").read()
versions = [(int(a), int(b)) for a, b in re.findall(r"GLIBC_(\d+)\.(\d+)", text)]
if versions and max(versions) > (2, 17):
    raise SystemExit("loader requires GLIBC_%d.%d, above the 2.17 floor" % max(versions))
PY
fi

"$nm" -D --defined-only --format=posix "$binary" > "$scratch/defined"
grep -E '^extension_version_info ' "$scratch/defined" >/dev/null
grep -E '^zend_extension_entry ' "$scratch/defined" >/dev/null
while read -r symbol _rest; do
    case "$symbol" in
        _init|_fini|extension_version_info|zend_extension_entry) ;;
        *)
            echo "unexpected public loader symbol: $symbol" >&2
            exit 1
            ;;
    esac
done < "$scratch/defined"

"$objdump" -h "$binary" > "$scratch/sections"
if grep -F '.debug_info' "$scratch/sections" >/dev/null; then
    echo "published loader must contain split debug information only" >&2
    exit 1
fi
grep -F '.gnu_debuglink' "$scratch/sections" >/dev/null
python3 "$debuglink_validator" "$objcopy" "$binary" "$debug"
"$objdump" -h "$debug" > "$scratch/debug-sections"
grep -F '.debug_info' "$scratch/debug-sections" >/dev/null

if grep -a -F '/worker/build/' "$binary" >/dev/null; then
    echo "remote worker path leaked into loader" >&2
    exit 1
fi
if grep -a -F '/worker/build/' "$debug" >/dev/null; then
    echo "remote worker path leaked into loader debug information" >&2
    exit 1
fi

grep -a -F "$version" "$binary" >/dev/null

printf 'loader ELF contract passed\n' > "$marker"
