#!/bin/bash

export NVM_DIR="${HOME}/.nvm"
if ! command -v npx &>/dev/null; then
  curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash - >/dev/null 2>&1
  . "${NVM_DIR}/nvm.sh" >/dev/null 2>&1
  nvm install --lts --no-progress >/dev/null 2>&1
fi
[ -s "${NVM_DIR}/nvm.sh" ] && . "${NVM_DIR}/nvm.sh" >/dev/null 2>&1

npm install -g @datadog/datadog-ci >/dev/null 2>&1
