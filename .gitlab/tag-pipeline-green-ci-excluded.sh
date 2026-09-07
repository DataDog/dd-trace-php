#!/usr/bin/env bash

# https://datadoghq.atlassian.net/wiki/spaces/APMINT/pages/6797330027/Exclude+a+pipeline+from+Green+CI+metrics
# Tag a pipeline as excluded from "green CI" tracking
#
# IMPORTANT : All jobs on default branches should succeed.
# A failure means some exceptional action is required, and Green CI tracks and surfaces these events.
#
# Before excluding a pipeline, consider what "fails on main" means here:
#
# * a failure needing no action shouldn't fail at all;
# * a failure part of routine daily process needs its own notification, since "fails on master" is easy to overlook.
#   And probably, it should not fail when the check returns false, keeping CI failure signal for real process failure
#
# Now, once considered those points, this script is here to exclude a whole pipeline from Green CI metrics.

set -uo pipefail

vault_path="/tmp/vault"
if [ ! -x "${vault_path}" ]; then
  curl -sL --fail \
    "https://releases.hashicorp.com/vault/1.20.0/vault_1.20.0_linux_amd64.zip" \
    --output "${vault_path}.zip" >/dev/null 2>&1 \
    && unzip -o -q "${vault_path}.zip" -d /tmp \
    && chmod +x "${vault_path}" \
    && rm -f "${vault_path}.zip"
fi

DD_API_KEY="$("${vault_path}" kv get --format=json \
  kv/k8s/gitlab-runner/dd-trace-php/datadoghq-api-key 2>/dev/null | jq -r '.data.data.key // empty' 2>/dev/null)"

if [ -z "${DD_API_KEY}" ]; then
  echo "WARNING: could not fetch DD_API_KEY from Vault — skipping green_ci.excluded tag" >&2
  exit 0
fi

export NVM_DIR="${HOME}/.nvm"
if ! command -v npx &>/dev/null; then
  curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash - >/dev/null 2>&1
  . "${NVM_DIR}/nvm.sh" >/dev/null 2>&1
  nvm install --lts --no-progress >/dev/null 2>&1
fi
[ -s "${NVM_DIR}/nvm.sh" ] && . "${NVM_DIR}/nvm.sh" >/dev/null 2>&1

npm install -g @datadog/datadog-ci >/dev/null 2>&1

DD_API_KEY="${DD_API_KEY}" DD_SITE="datadoghq.com" \
  datadog-ci tag --level pipeline --tags "green_ci.excluded:true" >/dev/null 2>&1 \
  || echo "WARNING: datadog-ci tag failed" >&2

unset DD_API_KEY

exit 0
