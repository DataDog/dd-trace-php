#!/bin/sh
# Tests for .gitlab/download-file.sh, run against stub curl and sleep commands.
set -u

tests_dir=$(dirname -- "$0")
# shellcheck source-path=SCRIPTDIR source=assert.sh
. "$tests_dir/assert.sh"
download_file="$tests_dir/../download-file.sh"

scratch=$(mktemp -d) || exit 1
trap 'rm -rf -- "$scratch"' EXIT
mkdir "$scratch/bin" || exit 1

cat > "$scratch/bin/curl" <<'EOF'
#!/bin/sh
# Stub curl: records the call, writes the download on success, prints the
# configured HTTP status and error text, and exits with the configured code.
echo call >> "$STUB_CURL_CALLS"
while [ "$#" -gt 0 ]; do
  if [ "$1" = --output ] && [ "$STUB_CURL_EXIT" -eq 0 ]; then
    printf 'downloaded' > "$2"
  fi
  shift
done
printf '%s' "$STUB_CURL_HTTP_STATUS"
printf '%s\n' "$STUB_CURL_ERROR" >&2
exit "$STUB_CURL_EXIT"
EOF
printf '#!/bin/sh\n' > "$scratch/bin/sleep"
chmod +x "$scratch/bin/curl" "$scratch/bin/sleep" || exit 1

# Runs download-file.sh against a stub curl that exits with code $1, HTTP
# status $2 and error text $3. Prints the exit status and the curl call count.
run_download() {
  rm -f -- "$scratch/destination"
  : > "$scratch/calls"
  download_status=0
  PATH="$scratch/bin:$PATH" STUB_CURL_CALLS="$scratch/calls" \
    STUB_CURL_EXIT="$1" STUB_CURL_HTTP_STATUS="$2" STUB_CURL_ERROR="$3" \
    "$download_file" https://example.invalid/file "$scratch/destination" \
    >/dev/null 2>&1 || download_status=$?
  echo "$download_status $(wc -l < "$scratch/calls" | tr -d ' ')"
}

assert_equal "successful download exits 0 after one attempt" "0 1" \
  "$(run_download 0 200 '')"
assert_equal "successful download publishes the file" downloaded \
  "$(cat "$scratch/destination")"
assert_equal "timeout exits 75 after four attempts" "75 4" \
  "$(run_download 28 000 'curl: (28) Connection timed out')"
assert_equal "certificate failure exits with its code after one attempt" "60 1" \
  "$(run_download 60 000 'curl: (60) SSL certificate problem')"
assert_equal "SSL connect peer reset exits 75 after four attempts" "75 4" \
  "$(run_download 35 000 'curl: (35) Recv failure: Connection reset by peer')"
assert_equal "other SSL connect error exits with its code after one attempt" \
  "35 1" "$(run_download 35 000 'curl: (35) OpenSSL SSL_connect: SSL_ERROR_SYSCALL')"
assert_equal "HTTP 503 exits 75 after four attempts" "75 4" \
  "$(run_download 22 503 'curl: (22) The requested URL returned error: 503')"
assert_equal "HTTP 404 exits with its code after one attempt" "22 1" \
  "$(run_download 22 404 'curl: (22) The requested URL returned error: 404')"

finish_tests
