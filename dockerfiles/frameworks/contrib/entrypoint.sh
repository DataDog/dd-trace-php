#!/bin/bash -xe
if [[ -z "$NO_DDTRACE" ]]; then
    curl -o /tmp/ddtrace.deb http://nginx_file_server/ddtrace.deb
    dpkg -i /tmp/ddtrace.deb
fi
exec "$@"
