#!/usr/bin/env sh

# Print out commands as they are executing.
set -x

# Stop process on non-zero return codes.
set -e

# Assign default value for temporary directory.
if [ "z$TMPDIR" = "z" ] ; then
    TMPDIR="/tmp"
fi

# Check early for a better user-experience {{{
if [ "z${1:-}" = "z" ] ; then
    >&2 echo 'ERROR: expected first argument to be set'
    exit 1
fi

system_name=`uname -s`

if [ ! -f "datadog.sym" ] ; then
    >&2 echo "ERROR: expected 'datadog.sym' to exist"
    exit 1
fi
if [ "$system_name" = "Linux" ] && [ ! -f "datadog-linux.sym" ] ; then
    >&2 echo "ERROR: expected 'datadog-linux.sym' to exist"
    exit 1
fi
# }}}

# Env vars have been processed; treat unset parameters as an error.
set -u

sofile=$1
actual_symbols=`mktemp "$TMPDIR/actual_symbols.XXXXXXXX"`
actual_defined_symbols=`mktemp "$TMPDIR/actual_defined_symbols.XXXXXXXX"`

# nm -g will only print the extern symbols, so most types can be ignored.
# nm output legend of symbol types encountered so far:
#     T is in text (code) section
#     U is undefined (needed but not included)
#     w is a weak symbol
nm -gC "$sofile" \
    | awk '$1 == "U" || $1 == "w" { next } $2 == "T" { print $3 }' \
    | sort > "$actual_symbols"
nm -gC "$sofile" \
    | awk '$1 == "U" || $1 == "w" { next } NF >= 3 { print $3 }' \
    | sort > "$actual_defined_symbols"

expected_symbols=`mktemp "$TMPDIR/expected_symbols.XXXXXXXX"`
if [ "$system_name" = "Darwin" ] ; then
    sed 's/^/_/' datadog.sym | sort > "$expected_symbols"
elif [ "$system_name" = "Linux" ] ; then
    sort datadog.sym datadog-linux.sym > "$expected_symbols"
else
    sort datadog.sym > "$expected_symbols"
fi

unexpected_symbols=`mktemp "$TMPDIR/unexpected_symbols.XXXXXXXX"`
# comm -13 will show lines that exist in file 2 that do not exist in file 1.
# comm expects the inputs to be sorted.
comm -13 "$expected_symbols" "$actual_symbols" > "$unexpected_symbols"

missing_platform_symbols=`mktemp "$TMPDIR/missing_platform_symbols.XXXXXXXX"`
if [ "$system_name" = "Linux" ] ; then
    required_platform_symbols=`mktemp "$TMPDIR/required_platform_symbols.XXXXXXXX"`
    sort datadog-linux.sym > "$required_platform_symbols"
    comm -23 "$required_platform_symbols" "$actual_defined_symbols" > "$missing_platform_symbols"
    rm "$required_platform_symbols"
fi

lines=`wc -l < "$unexpected_symbols"`
missing_lines=`wc -l < "$missing_platform_symbols"`

if [ $lines -gt 0 ] || [ $missing_lines -gt 0 ] ; then
    >&2 echo "ERROR: exported symbol verification failed! Printing diagnostics."

    # tail -n +1 is kind of like cat but prints file names before contents
    >&2 tail -n +1 \
        "$expected_symbols" \
        "$actual_symbols" \
        "$actual_defined_symbols" \
        "$unexpected_symbols" \
        "$missing_platform_symbols"

    rm \
        "$expected_symbols" \
        "$actual_symbols" \
        "$actual_defined_symbols" \
        "$unexpected_symbols" \
        "$missing_platform_symbols"
    exit 1
else
    rm \
        "$expected_symbols" \
        "$actual_symbols" \
        "$actual_defined_symbols" \
        "$unexpected_symbols" \
        "$missing_platform_symbols"
fi
