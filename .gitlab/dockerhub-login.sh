#!/bin/sh

set -e

export VAULT_VERSION="1.20.0"

echo "=== Setting up Docker Hub authentication ==="

# Determine architecture for binary downloads
arch="$(uname -m)"
case "${arch}" in
  x86_64)
    vault_arch="amd64"
    ;;
  aarch64|arm64)
    vault_arch="arm64"
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

  if ! curl -L --fail "https://github.com/jqlang/jq/releases/latest/download/jq-linux-${vault_arch}" \
      --output "${jq_path}"; then
    echo "Warning: Failed to download jq. Skipping Docker Hub authentication." >&2
    exit 0
  fi

  chmod +x "${jq_path}"
  export PATH="/tmp:${PATH}"
fi

# Install unzip if not already available
if ! command -v unzip > /dev/null 2>&1; then
  echo "Installing unzip..."
  if command -v apt-get > /dev/null 2>&1; then
    apt-get update -qq && apt-get install -y -qq unzip > /dev/null 2>&1 || {
      echo "Warning: Failed to install unzip. Skipping Docker Hub authentication." >&2
      exit 0
    }
  elif command -v apk > /dev/null 2>&1; then
    apk add --no-cache unzip > /dev/null 2>&1 || {
      echo "Warning: Failed to install unzip. Skipping Docker Hub authentication." >&2
      exit 0
    }
  else
    echo "Warning: No package manager found to install unzip. Skipping Docker Hub authentication." >&2
    exit 0
  fi
fi

# Install Vault if not already available
vault_cmd="vault"
if ! command -v vault > /dev/null 2>&1; then
  echo "Installing Vault CLI..."

  vault_path="/tmp/vault"
  vault_zip="${vault_path}.zip"

  if ! curl -L --fail "https://releases.hashicorp.com/vault/${VAULT_VERSION}/vault_${VAULT_VERSION}_linux_${vault_arch}.zip" \
      --output "${vault_zip}"; then
    echo "Warning: Failed to download Vault. Skipping Docker Hub authentication." >&2
    exit 0
  fi

  if ! unzip -q "${vault_zip}" -d /tmp; then
    echo "Warning: Failed to extract Vault. Skipping Docker Hub authentication." >&2
    exit 0
  fi

  chmod +x "${vault_path}"
  rm -f "${vault_zip}"

  vault_cmd="${vault_path}"
fi

# Fetch Docker Hub credentials from Vault
echo "Fetching Docker Hub credentials from Vault..."
vaultoutput="$("${vault_cmd}" kv get --format=json kv/k8s/gitlab-runner/dd-trace-php/dockerhub)" || {
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
