#!/usr/bin/env sh

set -e

# Fixing permissions, as this test is run in our own custom image using circleci as the executor
sudo chmod a+w ./build/packages/*

switch-php debug

sh "$(dirname ${0})/test_profiler_install_unsupported.sh"
