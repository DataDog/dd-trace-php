# shellcheck shell=sh
# Assertions for the CI script tests. Each failed assertion is counted, and
# finish_tests exits non-zero when any assertion failed.

assert_failures=0

# Reports whether value $3 equals the expected value $2 for the check
# described by $1.
assert_equal() {
  if [ "$2" = "$3" ]; then
    printf 'ok: %s\n' "$1"
  else
    printf 'FAIL: %s: expected %s, got %s\n' "$1" "$2" "$3"
    assert_failures=$((assert_failures + 1))
  fi
}

# Exits with status 1 when any assertion failed.
finish_tests() {
  if [ "$assert_failures" -ne 0 ]; then
    printf '%s assertion(s) failed\n' "$assert_failures"
    exit 1
  fi
}
