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

if [ ! -f "ddtrace.sym" ] ; then
    >&2 echo "ERROR: expected 'ddtrace.sym' to exist"
    exit 1
fi
# }}}

# Env vars have been processed; treat unset parameters as an error.
set -u

sofile=$1
actual_symbols=`mktemp "$TMPDIR/actual_symbols.XXXXXXXX"`

# nm -g will only print the extern symbols, so most types can be ignored.
# nm output legend of symbol types encountered so far:
#     T is in text (code) section
#     U is undefined (needed but not included)
#     w is a weak symbol
nm -gC "$sofile" \
    | awk '$1 == "U" || $1 == "w" { next } $2 == "T" { print $3 }' \
    | sort > "$actual_symbols"

expected_symbols=`mktemp "$TMPDIR/expected_symbols.XXXXXXXX"`
sort "ddtrace.sym" > "$expected_symbols"

unexpected_symbols=`mktemp "$TMPDIR/unexpected_symbols.XXXXXXXX"`
# comm -13 will show lines that exist in file 2 that do not exist in file 1.
# comm expects the inputs to be sorted.
comm -13 "$expected_symbols" "$actual_symbols" > "$unexpected_symbols"

lines=`wc -l < "$unexpected_symbols"`

if [ $lines -gt 0 ] ; then
    >&2 echo "ERROR: unexpected symbols! Printing diagnostics."

    # tail -n +1 is kind of like cat but prints file names before contents
    >&2 tail -n +1 "$expected_symbols" "$actual_symbols" "$unexpected_symbols"

    rm "$expected_symbols" "$actual_symbols" "$unexpected_symbols"
    exit 1
else
    rm "$expected_symbols" "$actual_symbols" "$unexpected_symbols"
fi
