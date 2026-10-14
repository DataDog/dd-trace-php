#!/usr/bin/env bash

# Download an HTTP(S) file, retrying only recognized transient failures.
# After four attempts, exit 75 so GitLab's bounded job retry can take over.
set -uo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 URL DESTINATION" >&2
    exit 64
fi

url=$1
destination=$2
if [ -d "$destination" ]; then
    echo "Download destination is a directory: $destination" >&2
    exit 1
fi

# Publish only a completed download; failed attempts must not replace a good file.
partial=$(mktemp "$(dirname -- "$destination")/.download.XXXXXX") || exit 1
curl_log=$(mktemp) || { rm -f -- "$partial"; exit 1; }
trap 'rm -f -- "$partial" "$curl_log"' EXIT

delay=1
for attempt in 1 2 3 4; do
    status=0
    http_status=$(curl --disable --fail --location --silent --show-error \
        --connect-timeout 15 --max-time 120 \
        --output "$partial" --write-out '%{http_code}' -- "$url" \
        2>"$curl_log") || status=$?
    cat "$curl_log" >&2

    if [ "$status" -eq 0 ]; then
        mv -f -- "$partial" "$destination"
        exit $?
    fi

    case "$status" in
        # DNS/proxy resolution, connection, partial transfer, timeout, empty
        # response, and send/receive failures can be transient for a download.
        5|6|7|18|28|52|55|56) ;;
        22)
            case "$http_status" in
                408|429|500|502|503|504) ;;
                *) exit "$status" ;;
            esac
            ;;
        35)
            # SSL-connect errors are not all transient. Retry the observed peer
            # reset, but preserve other TLS failures instead of retrying blindly.
            if ! grep -Fq 'Connection reset by peer' "$curl_log"; then
                exit "$status"
            fi
            ;;
        *) exit "$status" ;;
    esac

    if [ "$attempt" -eq 4 ]; then
        echo "Transient download failure after $attempt attempts; exiting 75 for GitLab retry." >&2
        exit 75
    fi
    echo "Transient download failure (curl $status, HTTP $http_status); retrying in ${delay}s (attempt $attempt/4)." >&2
    sleep "$delay" || exit $?
    delay=$((delay * 2))
done
