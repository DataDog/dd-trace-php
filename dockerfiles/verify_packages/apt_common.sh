# Retry a command up to 3 times with 10s backoff before signaling infra failure.
retry_or_tempfail() {
    local n=1 rc out
    out=$(mktemp)
    while :; do
        { rc=0; "$@" 2>&1 || rc=$?; echo "$rc" >"$out.rc"; } | tee "$out"
        rc=$(cat "$out.rc")
        [ "$rc" -eq 0 ] && break
        if grep -Eq '^E: .*snapshots/sury-php/.*404 +Not Found' "$out" ||
            { case "$*" in *snapshots/sury-php/*) true ;; *) false ;; esac && grep -q 'returned error: 404' "$out"; }; then
            echo "Command failed with a 404 from the Sury snapshot, which retrying cannot fix (exit $rc): $*" >&2
            rm -f "$out" "$out.rc"
            exit "$rc"
        fi
        if [ "$n" -ge 3 ]; then
            echo "Infrastructure command failed after $n attempts, signaling retry (exit 75): $*" >&2
            rm -f "$out" "$out.rc"
            exit 75
        fi
        echo "Command failed (attempt $n/3): $* — retrying in 10s..." >&2
        n=$((n + 1))
        sleep 10
    done
    rm -f "$out" "$out.rc"
}

# Bound apt: a stalled mirror fetch otherwise hangs until the 1 h job timeout.
apt_get() { timeout 300 apt-get -o Acquire::http::Timeout=30 -o Acquire::Retries=3 "$@"; }

SURY_MIRROR="${SURY_MIRROR:-https://depot-read-api-bzl.us1.ddbuild.io/debian.octopuce.fr/snapshots/sury-php/20261111010406/}"

add_sury_repo() {
    retry_or_tempfail curl -sSfL -o /etc/apt/trusted.gpg.d/php.gpg "${SURY_MIRROR}apt.gpg"
    printf 'deb %s %s main\n' "${SURY_MIRROR}" "$(lsb_release -sc)" > /etc/apt/sources.list.d/php.list
}
