#!/bin/bash

export NVM_DIR="${HOME}/.nvm"
if ! command -v npx &>/dev/null; then
  curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash -
  . "${NVM_DIR}/nvm.sh"
  nvm install --lts --no-progress
fi
[ -s "${NVM_DIR}/nvm.sh" ] && . "${NVM_DIR}/nvm.sh"

npm install -g @datadog/datadog-ci
