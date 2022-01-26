#!/usr/bin/env sh

set -e
switch-php debug
sh "$(dirname ${0})/test_profiler_install_unsupported.sh"
