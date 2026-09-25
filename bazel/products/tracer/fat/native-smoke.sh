#!/bin/sh
set -eu

loader=$1
library_path=$2
php=$3
extension=$4
expected_php=$5
expected_extension_file=$6
marker=$7
sidecar_runner=$8
sidecar_ping_client=$9

preflight() {
    binary=$1
    if ! loaded=$("$loader" --inhibit-cache --list --library-path "$library_path" "$binary"); then
        echo "dynamic loader preflight failed for $binary" >&2
        return 1
    fi
    resolved=0
    old_ifs=$IFS
    IFS='
'
    for line in $loaded; do
        case "$line" in
            *" => not found"*)
                echo "missing declared native dependency: $line" >&2
                IFS=$old_ifs
                return 1
                ;;
            *" => "*)
                path=${line#* => }
                path=${path%% *}
                allowed=0
                roots_ifs=$IFS
                IFS=:
                for root in $library_path; do
                    case "$path" in "$root"/*) allowed=1 ;; esac
                done
                IFS=$roots_ifs
                if [ "$allowed" -ne 1 ]; then
                    echo "native dependency escaped declared closure: $path" >&2
                    IFS=$old_ifs
                    return 1
                fi
                case "$path" in
                    *[Aa][Ss][Aa][Nn]*)
                        echo "normal tracer unexpectedly resolved ASan runtime: $path" >&2
                        IFS=$old_ifs
                        return 1
                        ;;
                esac
                resolved=$((resolved + 1))
                ;;
        esac
    done
    IFS=$old_ifs
    test "$resolved" -gt 0
}

preflight "$php"
preflight "$extension"
preflight "$sidecar_ping_client"

run_php() {
    "$loader" --inhibit-cache --library-path "$library_path" "$php" -n \
        -d "extension=$extension" "$@"
}

actual_php=$(run_php -r 'echo PHP_VERSION;')
test "$actual_php" = "$expected_php"
actual_extension=$(run_php -r '
if (!extension_loaded("ddtrace")) { fwrite(STDERR, "ddtrace did not load\n"); exit(10); }
if (!function_exists("DDTrace\\Testing\\trigger_error")) { fwrite(STDERR, "ddtrace functions missing\n"); exit(11); }
echo phpversion("ddtrace");
')
expected_extension=$(cat "$expected_extension_file")
test "$actual_extension" = "$expected_extension"

# A fat tracer is also the Linux sidecar executable. With no dispatch symbol
# requested, reaching the direct entry point has a deliberate, stable exit 2.
# Strong unresolved PHP references would instead make ld.so reject the DSO
# before its entry point can run.
set +e
sidecar_error=$("$loader" --inhibit-cache --library-path "$library_path" "$extension" 2>&1)
sidecar_status=$?
set -e
test "$sidecar_status" -eq 2
test "$sidecar_error" = "_DD_SIDECAR_DIRECT_EXEC is not set. Aborting."

python3 "$sidecar_runner" "$loader" "$library_path" "$extension" "$sidecar_ping_client"

printf 'ddtrace %s native PHP %s load/unload, direct entry, and sidecar native startup passed\n' \
    "$actual_extension" "$actual_php" >"$marker"
