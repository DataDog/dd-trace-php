#!/usr/bin/env bash
# build-for-system-tests.sh
#
# Convenience script to build the dd-trace-php extension and package it into
# the tar.gz bundle format that system-tests parametric testing expects.
#
# Designed for local development, especially on ARM Mac (Apple Silicon), where
# the standard CI pipeline is not easily available. Runs the build inside the
# official CI Docker container so the resulting .so is compatible with the
# system-tests Linux environment.
#
# Usage:
#   ./scripts/build-for-system-tests.sh [--copy-to <binaries-path>]
#
# Options:
#   --copy-to <path>   After building, copy datadog-setup.php and the tar.gz
#                      to <path> (e.g. your system-tests /binaries directory).
#
# Output:
#   build/packages/dd-library-php-<VERSION>-<arch>-linux-gnu.tar.gz
#
# Requirements:
#   - Docker (with access to datadog/dd-trace-ci images)
#   - Run from the root of the dd-trace-php repository

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

# PHP version and its corresponding Zend API version (PHP_API).
# PHP 8.2 NTS => API 20220829
PHP_VERSION="8.2"
PHP_API="20220829"

# The CI Docker image used for building the extension.
CI_IMAGE="datadog/dd-trace-ci:php-${PHP_VERSION}_buster"

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

COPY_TO=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --copy-to)
            if [[ -z "${2:-}" ]]; then
                echo "ERROR: --copy-to requires a path argument" >&2
                exit 1
            fi
            COPY_TO="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "ERROR: Unknown option: $1" >&2
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Resolve repository root and version
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ ! -f "${REPO_ROOT}/VERSION" ]]; then
    echo "ERROR: Could not find VERSION file at ${REPO_ROOT}/VERSION" >&2
    echo "       Make sure you are running from the dd-trace-php repository." >&2
    exit 1
fi

VERSION="$(cat "${REPO_ROOT}/VERSION")"
echo "==> Building dd-trace-php ${VERSION} for system-tests"

# ---------------------------------------------------------------------------
# Detect architecture
# ---------------------------------------------------------------------------

HOST_ARCH="$(uname -m)"
# Normalize to the naming convention used in artifact filenames
case "${HOST_ARCH}" in
    arm64|aarch64)
        ARCH="aarch64"
        ;;
    x86_64|amd64)
        ARCH="x86_64"
        ;;
    *)
        echo "WARNING: Unrecognised host architecture '${HOST_ARCH}', defaulting to x86_64 for artifact name." >&2
        ARCH="x86_64"
        ;;
esac
echo "==> Target architecture: ${ARCH}"

# ---------------------------------------------------------------------------
# Build ddtrace.so inside the CI container
# ---------------------------------------------------------------------------

echo "==> Building ddtrace.so using ${CI_IMAGE} ..."
echo "    (This may take several minutes on first run while Rust dependencies compile.)"

docker run --rm \
    -v "${REPO_ROOT}:/src" \
    -w /src \
    "${CI_IMAGE}" \
    bash -c "switch-php nts && make all"

# The build produces the .so at:
#   tmp/build_extension/modules/ddtrace.so
BUILT_SO="${REPO_ROOT}/tmp/build_extension/modules/ddtrace.so"

if [[ ! -f "${BUILT_SO}" ]]; then
    echo "ERROR: Build completed but ddtrace.so not found at ${BUILT_SO}" >&2
    exit 1
fi
echo "==> Build succeeded: ${BUILT_SO}"

# ---------------------------------------------------------------------------
# Package into the dd-library-php tar.gz bundle
# ---------------------------------------------------------------------------

PACKAGES_DIR="${REPO_ROOT}/build/packages"
ARTIFACT_NAME="dd-library-php-${VERSION}-${ARCH}-linux-gnu.tar.gz"
ARTIFACT_PATH="${PACKAGES_DIR}/${ARTIFACT_NAME}"

# Staging area for the bundle directory tree
TMP_BUNDLE="${REPO_ROOT}/tmp/build-for-system-tests-bundle"
TRACE_DIR="${TMP_BUNDLE}/dd-library-php/trace"

echo "==> Packaging into ${ARTIFACT_NAME} ..."

# Start from a clean staging directory
rm -rf "${TMP_BUNDLE}"
mkdir -p "${TRACE_DIR}/ext/${PHP_API}"
mkdir -p "${PACKAGES_DIR}"

# Place the compiled extension at the expected path within the bundle.
# Only the NTS (non-thread-safe) variant is needed for parametric tests.
cp "${BUILT_SO}" "${TRACE_DIR}/ext/${PHP_API}/ddtrace.so"

# Include the PHP source files that the tracer needs at runtime.
cp -r "${REPO_ROOT}/src" "${TRACE_DIR}/src"

# Write the version file at the bundle root.
echo "${VERSION}" > "${TMP_BUNDLE}/dd-library-php/VERSION"

# Create the tar.gz from the staging directory (no profiling, no appsec).
tar -czf "${ARTIFACT_PATH}" \
    -C "${TMP_BUNDLE}" \
    . \
    --owner=0 --group=0

# Clean up the staging area
rm -rf "${TMP_BUNDLE}"

echo "==> Package created: ${ARTIFACT_PATH}"

# ---------------------------------------------------------------------------
# Optional: copy artefacts to a system-tests binaries/ directory
# ---------------------------------------------------------------------------

if [[ -n "${COPY_TO}" ]]; then
    if [[ ! -d "${COPY_TO}" ]]; then
        echo "ERROR: --copy-to path does not exist: ${COPY_TO}" >&2
        exit 1
    fi

    echo "==> Copying artefacts to ${COPY_TO} ..."

    cp "${ARTIFACT_PATH}" "${COPY_TO}/"
    echo "    Copied ${ARTIFACT_NAME}"

    SETUP_PHP="${REPO_ROOT}/datadog-setup.php"
    if [[ -f "${SETUP_PHP}" ]]; then
        cp "${SETUP_PHP}" "${COPY_TO}/"
        echo "    Copied datadog-setup.php"
    else
        echo "    WARNING: datadog-setup.php not found at ${SETUP_PHP}, skipping." >&2
        echo "             You can download it from the latest GitHub release if needed." >&2
    fi

    echo "==> Artefacts copied to ${COPY_TO}"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

echo ""
echo "==> Done!"
echo "    Version   : ${VERSION}"
echo "    PHP API   : ${PHP_API} (PHP ${PHP_VERSION} NTS)"
echo "    Arch      : ${ARCH}"
echo "    Artifact  : ${ARTIFACT_PATH}"
if [[ -n "${COPY_TO}" ]]; then
    echo "    Copied to : ${COPY_TO}"
fi
echo ""
echo "To use with system-tests parametric testing, place the tar.gz and"
echo "datadog-setup.php in your system-tests binaries/ directory, then run:"
echo "  ./build.sh -i runner && python run.py PARAMETRIC ..."
