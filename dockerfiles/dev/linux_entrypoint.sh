#!/bin/bash
set -euo pipefail
# set -x

WORKING_DIR=$(pwd)
USER_ID=$(stat --format "%u" "${WORKING_DIR}")
GROUP_ID=$(stat --format "%g" "${WORKING_DIR}")

if grep alpine /etc/os-release >& /dev/null; then
    apk --no-cache add shadow >& /dev/null
fi

usermod -u "${USER_ID}" circleci
groupmod -g "${GROUP_ID}" circleci

# It takes a few seconds, so let's do it in a sub-process to let the container start fast
chown -R circleci:circleci /opt &

if [[ ! -f .tmp/gosu ]]; then
    mkdir -p .tmp
    curl -Lo .tmp/gosu "https://github.com/tianon/gosu/releases/download/1.17/gosu-amd64"
    chmod +x .tmp/gosu
    chown circleci:circleci -R .tmp
fi

exec .tmp/gosu circleci "${@:-bash}"
