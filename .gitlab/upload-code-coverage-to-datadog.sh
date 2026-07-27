#!/usr/bin/env bash

set -euo pipefail

if [[ $# -eq 0 ]]; then
  echo "Usage: $0 <coverage-report> [<coverage-report> ...]" >&2
  exit 2
fi

: "${CI_PROJECT_DIR:?CI_PROJECT_DIR must be set}"

export DD_SITE="${DD_SITE:-datadoghq.com}"

if [[ -n "${DD_API_KEY:-}" ]]; then
  :
elif [[ -n "${DATADOG_API_KEY:-}" ]]; then
  DD_API_KEY="${DATADOG_API_KEY}"
else
  vault_version="1.20.0"
  vault_path="/tmp/vault"
  vault_zip="${vault_path}.zip"

  if [[ ! -x "${vault_path}" ]]; then
    case "$(uname -m)" in
      x86_64)
        vault_arch="amd64"
        datadog_ci_arch="x64"
        ;;
      aarch64|arm64)
        vault_arch="arm64"
        datadog_ci_arch="arm64"
        ;;
      *)
        echo "ERROR: unsupported architecture for Vault: $(uname -m)" >&2
        exit 1
        ;;
    esac

    if ! curl -L --fail \
      "https://releases.hashicorp.com/vault/${vault_version}/vault_${vault_version}_linux_${vault_arch}.zip" \
      --output "${vault_zip}"; then
      echo "ERROR: failed to download Vault; exiting 75 so GitLab auto-retries" >&2
      exit 75
    fi
    unzip -o -q "${vault_zip}" -d /tmp
    chmod +x "${vault_path}"
    rm -f "${vault_zip}"
  fi

  if ! vault_output="$("${vault_path}" kv get --format=json \
    kv/k8s/gitlab-runner/dd-trace-php/datadoghq-api-key)"; then
    echo "ERROR: Vault unreachable while fetching DD_API_KEY; exiting 75 so GitLab auto-retries" >&2
    exit 75
  fi

  DD_API_KEY="$(jq -r '.data.data.key' <<< "${vault_output}")"
  if [[ -z "${DD_API_KEY}" || "${DD_API_KEY}" == "null" ]]; then
    echo "ERROR: DD_API_KEY empty/null after Vault fetch; exiting 75 so GitLab auto-retries" >&2
    exit 75
  fi
fi
export DD_API_KEY

datadog_ci_version="${DATADOG_CI_VERSION:-v5.9.1}"
datadog_ci_path="${DATADOG_CI_PATH:-/tmp/datadog-ci}"

if [[ -z "${datadog_ci_arch:-}" ]]; then
  case "$(uname -m)" in
    x86_64)
      datadog_ci_arch="x64"
      ;;
    aarch64|arm64)
      datadog_ci_arch="arm64"
      ;;
    *)
      echo "ERROR: unsupported architecture for datadog-ci: $(uname -m)" >&2
      exit 1
      ;;
  esac
fi

if [[ ! -x "${datadog_ci_path}" ]]; then
  if ! curl -L --fail \
    "https://github.com/DataDog/datadog-ci/releases/download/${datadog_ci_version}/datadog-ci_linux-${datadog_ci_arch}" \
    --output "${datadog_ci_path}"; then
    echo "ERROR: failed to download datadog-ci; exiting 75 so GitLab auto-retries" >&2
    exit 75
  fi
  chmod +x "${datadog_ci_path}"
fi

flags_args=()
if [[ -n "${DD_COVERAGE_FLAGS:-}" ]]; then
  IFS=',' read -ra coverage_flags <<< "${DD_COVERAGE_FLAGS}"
  for flag in "${coverage_flags[@]}"; do
    flags_args+=(--flags "${flag}")
  done
fi

cd "${CI_PROJECT_DIR}"

for report in "$@"; do
  if [[ ! -f "${report}" ]]; then
    echo "ERROR: coverage report not found: ${report}" >&2
    exit 1
  fi

  # Reports generated inside the build containers use /project as the checkout
  # root. Datadog expects source paths relative to the repository root.
  sed -i \
    -e 's|^SF:/project/|SF:|' \
    -e "s|^SF:${CI_PROJECT_DIR}/|SF:|" \
    "${report}"

  echo "Uploading ${report} to Datadog"
  "${datadog_ci_path}" coverage upload \
    --format=lcov \
    "${flags_args[@]}" \
    "${report}"
done
