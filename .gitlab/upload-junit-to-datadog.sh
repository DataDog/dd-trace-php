#!/usr/bin/env bash

set -eo pipefail

export DATADOG_SITE="datadoghq.com"
export DD_ENV="ci"
export DD_SERVICE="dd-trace-php-tests"
export VAULT_SECRET_PATH="kv/k8s/gitlab-runner/dd-trace-php/datadoghq-api-key"
export VAULT_VERSION="1.20.0"
export DATADOG_CI_DOWNLOAD_BASE="https://github.com/DataDog/datadog-ci/releases/latest/download"

# Parse arguments for tags (e.g., component:tracer test.type:unit)
TAGS="${*}"

echo "=== Uploading JUnit reports to Datadog Test Optimization ==="
if [[ -n "${TAGS}" ]]; then
  echo "Tags: ${TAGS}"
fi

arch="$(uname -m)"
case "${arch}" in
  x86_64)
    vault_arch="amd64"
    datadog_ci_arch="linux-x64"
    ;;
  aarch64|arm64)
    vault_arch="arm64"
    datadog_ci_arch="linux-arm64"
    ;;
  *)
    echo "Warning: Unsupported architecture: ${arch}. Skipping JUnit upload." >&2
    exit 0
    ;;
esac

# Install jq if not already available
if ! command -v jq &> /dev/null; then
  echo "Installing jq..."

  jq_path="/tmp/jq"

  if ! curl -L --fail "https://github.com/jqlang/jq/releases/latest/download/jq-linux-${vault_arch}" \
      --output "${jq_path}"; then
    echo "Warning: Failed to download jq. Skipping JUnit upload." >&2
    exit 0
  fi

  chmod +x "${jq_path}"
  export PATH="/tmp:${PATH}"
fi

# Install Vault if not already available
vault_cmd="vault"
if ! command -v vault &> /dev/null; then
  echo "Installing Vault CLI..."

  vault_path="/tmp/vault"
  vault_zip="${vault_path}.zip"

  if ! curl -L --fail "https://releases.hashicorp.com/vault/${VAULT_VERSION}/vault_${VAULT_VERSION}_linux_${vault_arch}.zip" \
      --output "${vault_zip}"; then
    echo "Warning: Failed to download Vault. Skipping JUnit upload." >&2
    exit 0
  fi

  if ! unzip -q "${vault_zip}" -d /tmp; then
    echo "Warning: Failed to extract Vault. Skipping JUnit upload." >&2
    exit 0
  fi

  chmod +x "${vault_path}"
  rm -f "${vault_zip}"

  vault_cmd="${vault_path}"
fi

# Fetch DATADOG_API_KEY from Vault if not already set
if [[ -z "${DATADOG_API_KEY:-}" ]]; then
  echo "DATADOG_API_KEY not set, attempting to fetch from Vault..."

  DATADOG_API_KEY="$("${vault_cmd}" kv get --format=json "${VAULT_SECRET_PATH}" | jq -r '.data.data.key')" || {
    echo "Warning: Failed to fetch DATADOG_API_KEY from Vault. Skipping JUnit upload." >&2
    exit 0
  }

  if [[ -z "${DATADOG_API_KEY}" ]]; then
    echo "Warning: DATADOG_API_KEY is empty after fetching from Vault. Skipping JUnit upload." >&2
    exit 0
  fi

  echo "Successfully fetched DATADOG_API_KEY from Vault"
fi

export DATADOG_API_KEY

# Install datadog-ci standalone binary if not already installed
datadog_ci_cmd="datadog-ci"
if ! command -v datadog-ci &> /dev/null; then
  echo "Installing datadog-ci standalone binary..."

  datadog_ci_path="/tmp/datadog-ci"
  if ! curl -L --fail "${DATADOG_CI_DOWNLOAD_BASE}/datadog-ci_${datadog_ci_arch}" \
      --output "${datadog_ci_path}"; then
    echo "Warning: Failed to download datadog-ci. Skipping JUnit upload." >&2
    exit 0
  fi

  chmod +x "${datadog_ci_path}"

  datadog_ci_cmd="${datadog_ci_path}"
fi

# Find and upload all found JUnit XML files from artifacts directory
junit_files="$(find "${CI_PROJECT_DIR}/artifacts" -type f -name '*.xml' || true)"

if [[ -z "${junit_files}" ]]; then
  echo "No JUnit XML files found in artifacts directory. Skipping upload."
  exit 0
fi

echo "Found JUnit files to upload:"
echo "${junit_files}"

mapfile -t files_array <<< "${junit_files}"

echo "Uploading ${#files_array[@]} JUnit file(s) to Datadog..."

cd "${CI_PROJECT_DIR}" && pwd

# Build tags argument if provided
tags_args=""
if [[ -n "${TAGS}" ]]; then
  tags_args="--tags ${TAGS}"
fi

echo "Current directory: $(pwd)"
echo "Running command: ${datadog_ci_cmd} junit upload --service \"${DD_SERVICE}\" --max-concurrency 20 --verbose ${tags_args} ${files_array[*]}"

if ! ${datadog_ci_cmd} junit upload --service "${DD_SERVICE}" --max-concurrency 20 --verbose ${tags_args} "${files_array[@]}"; then
  echo "Warning: Failed to upload JUnit files" >&2
  exit 0
fi

echo "=== JUnit upload completed ==="
