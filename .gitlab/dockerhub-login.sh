#!/bin/sh

set -e

echo "=== Setting up Docker Hub authentication ==="

# Determine architecture for jq binary download
arch="$(uname -m)"
case "${arch}" in
  x86_64)
    jq_arch="amd64"
    ;;
  aarch64|arm64)
    jq_arch="arm64"
    ;;
  *)
    echo "Warning: Unsupported architecture: ${arch}. Skipping Docker Hub authentication." >&2
    exit 0
    ;;
esac

# Install jq if not already available
if ! command -v jq > /dev/null 2>&1; then
  echo "Installing jq..."

  jq_path="/tmp/jq"

  if ! curl -L --fail "https://github.com/jqlang/jq/releases/latest/download/jq-linux-${jq_arch}" \
      --output "${jq_path}"; then
    echo "Warning: Failed to download jq. Skipping Docker Hub authentication." >&2
    exit 0
  fi

  chmod +x "${jq_path}"
  export PATH="/tmp:${PATH}"
fi

# Fetch Docker Hub credentials from Vault
echo "Fetching Docker Hub credentials from Vault..."
vaultoutput="$(curl -sf -H "X-Vault-Token:${VAULT_TOKEN}" "${VAULT_ADDR}/v1/kv/data/k8s/gitlab-runner/dd-trace-php/dockerhub")" || {
  echo "Warning: Failed to fetch Docker Hub credentials from Vault. Skipping Docker Hub authentication." >&2
  exit 0
}

user="$(echo "$vaultoutput" | jq -r '.data.data.user')"
token="$(echo "$vaultoutput" | jq -r '.data.data.token')"

if [ -z "${user}" ] || [ -z "${token}" ] || [ "${user}" = "null" ] || [ "${token}" = "null" ]; then
  echo "Warning: Docker Hub credentials are empty or invalid. Skipping Docker Hub authentication." >&2
  exit 0
fi

echo "Docker Hub user: ${user}"
echo "Logging in to Docker Hub..."
if ! echo "${token}" | docker login -u "${user}" --password-stdin docker.io; then
  echo "Warning: Failed to login to Docker Hub. Continuing without authentication." >&2
  exit 0
fi

echo "=== Docker Hub authentication successful ==="
