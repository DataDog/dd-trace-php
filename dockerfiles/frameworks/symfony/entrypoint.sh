#!/bin/bash -xe

if [ -e /shared/ddtrace.deb ]; then
    dpkg -i /shared/ddtrace.deb
fi

export DD_TRACE_CLI_ENABLED=true
exec "$@"
