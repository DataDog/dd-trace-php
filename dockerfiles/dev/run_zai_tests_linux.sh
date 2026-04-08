#!/usr/bin/env bash
# Run zend_abstract_interface tests (including "SAPI env takes priority over cache")
# inside the dd-trace-php 8.5-bookworm Linux container.
#
# Usage (from repo root):
#   ./dockerfiles/dev/run_zai_tests_linux.sh
#     → runs this script inside the container (container entrypoint must pass through)
#
#   ./dockerfiles/dev/run_zai_tests_linux.sh --docker
#     → starts 8.5-bookworm and runs this script inside it (use from host)
#
# With Linux override (recommended on Linux hosts):
#   docker compose -f docker-compose.yml -f docker-compose.linux.override.yml run --rm --no-deps 8.5-bookworm ./dockerfiles/dev/run_zai_tests_linux.sh

set -euo pipefail

if [[ "${1:-}" == "--docker" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
  cd "${REPO_ROOT}"
  COMPOSE_FILES="-f docker-compose.yml"
  [[ "$(uname)" != "Darwin" && -f docker-compose.linux.override.yml ]] && COMPOSE_FILES="${COMPOSE_FILES} -f docker-compose.linux.override.yml"
  # Use container path; repo is mounted at /home/circleci/app
  # Named volume zai_linux_build persists build artifacts across runs for faster incremental builds.
  # Docker creates named volumes owned by root, so fix permissions first (idempotent).
  docker run --rm -v zai_linux_build:/build alpine chmod 777 /build
  exec docker compose ${COMPOSE_FILES} run --rm --no-deps \
    -v zai_linux_build:/home/circleci/app/tmp/linux_zai_build \
    7.0-bookworm /home/circleci/app/dockerfiles/dev/run_zai_tests_linux.sh
fi

APP_DIR="${APP_DIR:-/home/circleci/app}"
# In CI image, default PHP is switch-php debug → /opt/php/debug
PHP_PREFIX="${PHP_PREFIX:-/opt/php/debug}"
BUILD_DIR="${APP_DIR}/tmp/linux_zai_build"
TEA_BUILD="${BUILD_DIR}/tea-build"
TEA_INSTALL="${BUILD_DIR}/tea-install"
ZAI_BUILD="${BUILD_DIR}/zai-build"

echo "=== PHP ==="
php -v
php-config --version
echo "PhpConfig_ROOT=${PHP_PREFIX}"

echo "=== Build Tea ==="
mkdir -p "${TEA_BUILD}" "${TEA_INSTALL}"
cd "${TEA_BUILD}"
if [[ ! -f CMakeCache.txt ]]; then
  cmake -DPhpConfig_ROOT="${PHP_PREFIX}" \
    -DCMAKE_BUILD_TYPE=Debug \
    -DCMAKE_INSTALL_PREFIX="${TEA_INSTALL}" \
    "${APP_DIR}/tea"
fi
cmake --build .
cmake --install .

echo "=== Build zend_abstract_interface with tests ==="
mkdir -p "${ZAI_BUILD}"
cd "${ZAI_BUILD}"
if [[ ! -f CMakeCache.txt ]]; then
  cmake -DPhpConfig_ROOT="${PHP_PREFIX}" \
    -DBUILD_ZAI_TESTING=ON \
    -DTea_DIR="${TEA_INSTALL}/cmake" \
    -DCMAKE_BUILD_TYPE=Debug \
    "${APP_DIR}/zend_abstract_interface"
fi
cmake --build .

echo "=== Run config tests (SAPI env takes priority) ==="
ctest -R "SAPI env takes priority" --output-on-failure -V

echo "=== Run all config tests ==="
ctest -R "config/" --output-on-failure

echo "=== Run all env tests ==="
ctest -R "env/" --output-on-failure
