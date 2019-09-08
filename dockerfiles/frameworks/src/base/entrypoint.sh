#!/bin/bash -xe
if [[ -z "$NO_DDTRACE" ]]; then
    curl -o /tmp/ddtrace.deb http://nginx_file_server/ddtrace.deb
    dpkg -i /tmp/ddtrace.deb

    export DD_TRACE_CLI_ENABLED=true
fi
exec "$@"
