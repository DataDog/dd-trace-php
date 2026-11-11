#!/bin/sh
# Tests for is_transient_curl_error in .gitlab/transient-curl-error.sh.
set -u

tests_dir=$(dirname -- "$0")
# shellcheck source-path=SCRIPTDIR source=assert.sh
. "$tests_dir/assert.sh"
# shellcheck source-path=SCRIPTDIR source=../transient-curl-error.sh
. "$tests_dir/../transient-curl-error.sh"

# Prints the status of is_transient_curl_error for curl exit code $1 and
# error text $2.
classify() {
  if is_transient_curl_error "$1" "$2"; then
    echo 0
  else
    echo 1
  fi
}

for code in 5 6 7 16 18 28 52 55 56 92; do
  assert_equal "curl exit code $code is transient" 0 \
    "$(classify "$code" "curl: ($code) failure")"
done

assert_equal "SSL connect error with a peer reset is transient" 0 \
  "$(classify 35 'curl: (35) Recv failure: Connection reset by peer')"
assert_equal "SSL connect error without a peer reset is permanent" 1 \
  "$(classify 35 'curl: (35) OpenSSL SSL_connect: SSL_ERROR_SYSCALL')"
assert_equal "certificate verification failure is permanent" 1 \
  "$(classify 60 'curl: (60) SSL certificate problem')"
assert_equal "HTTP error status is permanent" 1 \
  "$(classify 22 'curl: (22) The requested URL returned error: 503')"
assert_equal "empty exit code is permanent" 1 "$(classify '' '')"

finish_tests
