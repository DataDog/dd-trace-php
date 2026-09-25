set -eu

preflight=$1
shift
. "$preflight"

loader=$1
lib_root=$2
php=$3
tokenizer=$4
expected_version=$5
expected_arch=$6
marker=$7

preflight_host_php "$loader" "$lib_root" "$php" "$lib_root"

# A deterministic outside directory models a host fallback.  The preflight
# must reject it even though the dynamic loader could satisfy the dependency.
negative="$marker.negative"
outside="$marker.outside"
mkdir -p "$negative" "$outside"
omitted=0
for library in "$lib_root"/*; do
    name=${library##*/}
    case "$library" in
        /*) absolute_library=$library ;;
        *) absolute_library=$PWD/$library ;;
    esac
    if [ "$name" = libc.so.6 ]; then
        ln -s "$absolute_library" "$outside/$name"
        omitted=1
    else
        ln -s "$absolute_library" "$negative/$name"
    fi
done
test "$omitted" -eq 1
if negative_error=$(preflight_host_php "$loader" "$negative:$outside" "$php" "$negative" 2>&1); then
    echo "execution PHP preflight accepted an outside fallback library" >&2
    exit 1
fi
case "$negative_error" in
    *"$outside/libc.so.6"*) ;;
    *) echo "execution PHP negative preflight failed for the wrong reason: $negative_error" >&2; exit 1 ;;
esac
rm -rf "$negative" "$outside"

run_php() {
    if [ -n "$tokenizer" ]; then
        "$loader" --inhibit-cache --library-path "$lib_root" "$php" -n -d "extension=$tokenizer" "$@"
    else
        "$loader" --inhibit-cache --library-path "$lib_root" "$php" -n "$@"
    fi
}

actual_version=$(run_php -r 'echo PHP_VERSION;')
test "$actual_version" = "$expected_version"
run_php -r 'exit(extension_loaded("tokenizer") ? 0 : 1);'
run_php -r 'exit(extension_loaded("ddtrace") || extension_loaded("datadog-profiling") || extension_loaded("ddappsec") ? 1 : 0);'
actual_machine=$(uname -m)
case "$expected_arch:$actual_machine" in
    amd64:x86_64|arm64:aarch64) ;;
    *) echo "execution PHP platform mismatch: expected $expected_arch, got $actual_machine" >&2; exit 1 ;;
esac

printf 'host PHP %s %s with tokenizer passed\n' "$expected_version" "$expected_arch" > "$marker"
