#!/bin/bash -xe
curl -o /tmp/ddtrace.deb http://nginx_file_server/ddtrace.deb || true

if [ -e /tmp/ddtrace.deb ]; then
    dpkg -i /tmp/ddtrace.deb
fi

export DD_TRACE_CLI_ENABLED=true
exec "$@"
