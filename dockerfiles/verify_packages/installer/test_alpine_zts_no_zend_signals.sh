#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

apk add libgcc

# Initially no ddtrace
assert_no_ddtrace

# Install using the php installer
version=$(cat VERSION)
php ./build/packages/datadog-setup.php --enable-profiling --php-bin php
assert_ddtrace_version "${version}"
assert_profiler_version "${version}"
case "${version}" in
    1.25.0|1.25.1)
        # Released musl/ZTS AppSec binaries require __cxa_thread_atexit_impl.
        # Fixed for subsequent releases by #4180.
        echo "SKIPPED: AppSec ${version} has a known musl/ZTS loading failure"
        ;;
    *)
        assert_appsec_version "${version}"
        ;;
esac

assert_sources_path_exists
