set -eu

objdump=$1
library=$2
header=$3
arch=$4
libc=$5
version=$6
marker=$7
sysroot=$8
shift 8
expected_count=$1
shift

scratch=${TMPDIR:-/tmp}/target-curl.$$
mkdir -p "$scratch"
trap 'rm -rf "$scratch"' EXIT HUP INT TERM

"$objdump" -f "$library" > "$scratch/header"
case "$arch" in
    amd64) expected_arch=x86_64 ;;
    arm64) expected_arch=aarch64 ;;
    *) echo "unsupported curl SDK architecture: $arch" >&2; exit 1 ;;
esac
grep -F "architecture: $expected_arch" "$scratch/header" >/dev/null
grep -F "#define LIBCURL_VERSION \"$version\"" "$header" >/dev/null

"$objdump" -p "$library" > "$scratch/dynamic"
test "$(sed -n 's/^ *SONAME  *//p' "$scratch/dynamic")" = libcurl.so.4
if grep -E 'RPATH|RUNPATH|TEXTREL' "$scratch/dynamic" >/dev/null; then
    echo "curl SDK library contains forbidden dynamic metadata" >&2
    exit 1
fi
sed -n 's/^ *NEEDED  *//p' "$scratch/dynamic" | sort -u > "$scratch/needed"
: > "$scratch/expected"
while test "$expected_count" -gt 0; do
    printf '%s\n' "$1" >> "$scratch/expected"
    shift
    expected_count=$((expected_count - 1))
done
sort -u "$scratch/expected" -o "$scratch/expected"
cmp "$scratch/expected" "$scratch/needed"

check_glibc_floor() {
    elf=$1
    "$objdump" -T "$elf" > "$scratch/symbols"
    sed -n 's/.*GLIBC_\([0-9][0-9.]*\).*/\1/p' "$scratch/symbols" > "$scratch/glibc-versions"
    while IFS=. read -r major minor rest; do
        minor=${minor:-0}
        if test "$major" -gt 2 || { test "$major" -eq 2 && test "$minor" -gt 17; }; then
            echo "$(basename "$elf") requires GLIBC_$major.$minor above the 2.17 floor" >&2
            exit 1
        fi
    done < "$scratch/glibc-versions"
}

if test "$libc" = glibc; then
    check_glibc_floor "$library"
fi

found_crypto=0
found_ssl=0
found_zlib=0
for runtime in "$@"; do
    basename=${runtime##*/}
    if test -e "$scratch/runtime.$basename"; then
        echo "duplicate curl runtime dependency: $basename" >&2
        exit 1
    fi
    printf '%s\n' "$runtime" > "$scratch/runtime.$basename"
    case "$basename" in
        libcrypto.so.1.1|libcrypto.so.3) found_crypto=$((found_crypto + 1)) ;;
        libssl.so.1.1|libssl.so.3) found_ssl=$((found_ssl + 1)) ;;
        libz.so.1) found_zlib=$((found_zlib + 1)) ;;
    esac
    "$objdump" -f "$runtime" > "$scratch/runtime"
    grep -F "architecture: $expected_arch" "$scratch/runtime" >/dev/null
    "$objdump" -p "$runtime" > "$scratch/runtime-dynamic"
    if grep -E 'RPATH|RUNPATH|TEXTREL' "$scratch/runtime-dynamic" >/dev/null; then
        echo "$basename contains forbidden dynamic metadata" >&2
        exit 1
    fi
    if test "$libc" = glibc; then
        check_glibc_floor "$runtime"
    fi
done
test "$found_crypto" -eq 1
test "$found_ssl" -eq 1
test "$found_zlib" -eq 1

find_sysroot_library() {
    name=$1
    found=
    for directory in "$sysroot/lib" "$sysroot/lib64" "$sysroot/usr/lib" "$sysroot/usr/lib64"; do
        test -d "$directory" || continue
        candidate=$directory/$name
        if test -e "$candidate"; then
            found=$candidate
            break
        fi
    done
    test -n "$found" || {
        echo "curl runtime closure cannot resolve $name in declared inputs" >&2
        exit 1
    }
}

for elf in "$library" "$@"; do
    "$objdump" -p "$elf" > "$scratch/closure-dynamic"
    sed -n 's/^ *NEEDED  *//p' "$scratch/closure-dynamic" > "$scratch/closure-needed"
    while IFS= read -r needed; do
        if test -e "$scratch/runtime.$needed"; then
            continue
        fi
        find_sysroot_library "$needed"
    done < "$scratch/closure-needed"
done

printf 'curl %s %s-%s SDK passed\n' "$version" "$arch" "$libc" > "$marker"
