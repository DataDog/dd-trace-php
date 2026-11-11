# shellcheck shell=sh
# Classification of curl failures that a CI download may retry.

# Succeeds when curl exit code $1 is a transient network failure. $2 is the
# error text curl reported for the failure.
is_transient_curl_error() {
  case "$1" in
    # DNS/proxy resolution, connection, partial transfer, timeout, empty
    # response, send/receive, and HTTP/2 framing/stream failures.
    5|6|7|16|18|28|52|55|56|92) return 0 ;;
    35)
      # Of the SSL-connect errors, only the observed peer reset is transient.
      # Other TLS failures, such as handshake rejections, are permanent.
      case "$2" in
        *'Connection reset by peer'*) return 0 ;;
      esac
      ;;
  esac
  return 1
}
