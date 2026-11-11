#!/bin/sh
# Tests for the composer download rule of .gitlab/run-with-retryable-download.sh.
set -u

tests_dir=$(dirname -- "$0")
# shellcheck source-path=SCRIPTDIR source=assert.sh
. "$tests_dir/assert.sh"
wrapper="$tests_dir/../run-with-retryable-download.sh"

# Runs the wrapper around a command that prints $2 and exits with status $1.
# Prints the wrapper's exit status.
run_wrapped() {
  wrapped_status=0
  # shellcheck disable=SC2016 # the inner sh expands its positional parameters
  "$wrapper" sh -c 'printf "%s\n" "$2"; exit "$1"' sh "$1" "$2" \
    >/dev/null 2>&1 || wrapped_status=$?
  echo "$wrapped_status"
}

assert_equal "successful command exits 0" 0 "$(run_wrapped 0 'done')"
assert_equal "composer error printed by a successful command exits 0" 0 \
  "$(run_wrapped 0 'curl error 28 while downloading https://getcomposer.org/versions: SSL connection timeout')"
assert_equal "unrelated failure keeps its status" 3 \
  "$(run_wrapped 3 'Your requirements could not be resolved to an installable set of packages.')"
assert_equal "composer timeout exits 75" 75 \
  "$(run_wrapped 1 '  curl error 28 while downloading https://getcomposer.org/versions: SSL connection timeout  ')"
assert_equal "composer timeout inside ANSI colors exits 75" 75 \
  "$(run_wrapped 1 "$(printf '\033[37;41m  curl error 28 while downloading https://repo.packagist.org/p2/psr/log.json: Connection timed out  \033[39;49m')")"
assert_equal "composer certificate failure keeps its status" 1 \
  "$(run_wrapped 1 'curl error 60 while downloading https://repo.packagist.org/packages.json: SSL certificate problem: unable to get local issuer certificate')"
assert_equal "composer SSL connect peer reset exits 75" 75 \
  "$(run_wrapped 1 'curl error 35 while downloading https://getcomposer.org/versions: Recv failure: Connection reset by peer')"
assert_equal "other composer SSL connect error keeps its status" 1 \
  "$(run_wrapped 1 'curl error 35 while downloading https://getcomposer.org/versions: OpenSSL SSL_connect: SSL_ERROR_SYSCALL')"

finish_tests
