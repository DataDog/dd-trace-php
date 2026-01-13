#!/usr/bin/env bash

set -eo pipefail

export DATADOG_SITE="datadoghq.com"
export DD_ENV="ci"
export DD_SERVICE="dd-trace-php-tests"
export VAULT_SECRET_PATH="kv/k8s/gitlab-runner/dd-trace-php/datadoghq-api-key"
export VAULT_VERSION="1.20.0"

# Parse arguments for tags (e.g., component:tracer test.type:unit)
TAGS="${*}"

echo "=== Uploading JUnit reports to Datadog Test Optimization ==="
if [[ -n "${TAGS}" ]]; then
  echo "Tags: ${TAGS}"
fi

# Detect architecture and map to different naming conventions
arch="$(uname -m)"
case "${arch}" in
  x86_64)
    vault_arch="amd64"
    datadog_ci_arch="x64"
    ;;
  aarch64|arm64)
    vault_arch="arm64"
    datadog_ci_arch="arm64"
    ;;
  *)
    echo "Warning: Unsupported architecture: ${arch}. Skipping JUnit upload." >&2
    exit 0
    ;;
esac

# Detect package manager and install dependencies
echo "Installing required dependencies (curl, jq, nodejs, npm, unzip)..."

is_alpine=false
if command -v apk &> /dev/null; then
  # Alpine Linux
  is_alpine=true
  echo "Using apk package manager..."
  apk add --no-cache curl jq nodejs npm unzip || {
    echo "Warning: Failed to install dependencies. Skipping JUnit upload." >&2
    exit 0
  }
elif command -v apt-get &> /dev/null; then
  # Debian/Ubuntu
  echo "Using apt-get package manager..."

  # Detect if we need sudo
  use_sudo=""
  if [ "$(id -u)" -ne 0 ] && command -v sudo &> /dev/null; then
    use_sudo="sudo"
  fi

  echo "Running apt-get update..."
  $use_sudo apt-get update || {
    echo "Warning: apt-get update failed. Skipping JUnit upload." >&2
    exit 0
  }

  echo "Installing packages individually..."
  # Install packages one by one, continue if some fail
  for pkg in curl jq unzip nodejs npm; do
    if ! command -v $pkg &> /dev/null; then
      echo "Installing $pkg..."
      $use_sudo apt-get install -y $pkg || echo "Warning: Failed to install $pkg, continuing..."
    else
      echo "$pkg is already available"
    fi
  done

  # Check critical dependencies
  if ! command -v curl &> /dev/null; then
    echo "Warning: curl is required but not available. Skipping JUnit upload." >&2
    exit 0
  fi
  if ! command -v jq &> /dev/null; then
    echo "Warning: jq is required but not available. Skipping JUnit upload." >&2
    exit 0
  fi
else
  echo "Warning: Unsupported package manager. Skipping JUnit upload." >&2
  exit 0
fi

echo "Dependencies installed successfully"

# Install Vault if not already available
if ! command -v vault &> /dev/null; then
  echo "Installing Vault CLI..."

  vault_path="/tmp/vault"
  vault_zip="${vault_path}.zip"

  echo "Downloading Vault ${VAULT_VERSION} for ${vault_arch}..."
  if ! curl -L --fail "https://releases.hashicorp.com/vault/${VAULT_VERSION}/vault_${VAULT_VERSION}_linux_${vault_arch}.zip" \
      --output "${vault_zip}"; then
    echo "Warning: Failed to download Vault. Skipping JUnit upload." >&2
    exit 0
  fi

  echo "Extracting Vault..."
  if ! unzip -q "${vault_zip}" -d /tmp; then
    echo "Warning: Failed to extract Vault. Skipping JUnit upload." >&2
    exit 0
  fi

  chmod +x "${vault_path}"
  rm -f "${vault_zip}"

  echo "Vault installed successfully"
fi

# Fetch DATADOG_API_KEY from Vault if not already set
if [[ -z "${DATADOG_API_KEY:-}" ]]; then
  echo "DATADOG_API_KEY not set, attempting to fetch from Vault..."

  # Use the downloaded vault binary if it exists, otherwise use system vault
  vault_cmd="vault"
  if [ -f "/tmp/vault" ]; then
    vault_cmd="/tmp/vault"
  fi

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

# Determine which datadog-ci method to use
datadog_ci_cmd=""

# Prefer npm/npx if available
if command -v npx &> /dev/null || command -v npm &> /dev/null; then
  echo "Using npx to run datadog-ci"
  datadog_ci_cmd="npx --yes @datadog/datadog-ci"
else
  # Fall back to standalone binary if npm/npx not available
  echo "npm/npx not available, attempting to use standalone binary..."

  # Skip standalone binary on Alpine (musl libc incompatibility)
  if [ "$is_alpine" = true ]; then
    echo "Warning: Alpine uses musl libc which is incompatible with the prebuilt datadog-ci binary." >&2
    echo "Warning: npm/npx is required but not available. Skipping JUnit upload." >&2
    exit 0
  fi

  # Download standalone binary for glibc-based systems
  echo "Downloading datadog-ci standalone binary..."

  datadog_ci_path="/tmp/datadog-ci"
  datadog_ci_url="https://github.com/DataDog/datadog-ci/releases/latest/download/datadog-ci_linux-${datadog_ci_arch}"

  if ! curl -L --fail "${datadog_ci_url}" --output "${datadog_ci_path}"; then
    echo "Warning: Failed to download datadog-ci binary. Skipping JUnit upload." >&2
    exit 0
  fi

  chmod +x "${datadog_ci_path}"
  echo "datadog-ci binary downloaded successfully"
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

# Normalize absolute paths to relative paths in JUnit XML files
echo "Normalizing file paths in JUnit XML files..."
for file in "${files_array[@]}"; do
  if [[ -f "${file}" ]]; then
    sed -i "s|${CI_PROJECT_DIR}/||g" "${file}"
  fi
done

echo "Uploading ${#files_array[@]} JUnit file(s) to Datadog..."

cd "${CI_PROJECT_DIR}" && pwd

# Build tags argument if provided
tags_args=""
if [[ -n "${TAGS}" ]]; then
  tags_args="--tags ${TAGS}"
fi

echo "Current directory: $(pwd)"
echo "Running command: ${datadog_ci_cmd} junit upload --service \"${DD_SERVICE}\" --max-concurrency 20 --verbose --tags git.repository_url:https://github.com/DataDog/dd-trace-php ${tags_args} ${files_array[*]}"

if ! ${datadog_ci_cmd} junit upload --service "${DD_SERVICE}" --max-concurrency 20 --verbose --tags "git.repository_url:https://github.com/DataDog/dd-trace-php" ${tags_args} "${files_array[@]}"; then
  echo "Warning: Failed to upload JUnit files" >&2
  exit 0
fi

echo "=== JUnit upload completed ==="
