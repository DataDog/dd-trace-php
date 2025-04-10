#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

if [ "${ASAN}" = "true" ]; then
    DDTRACE_MAKE_PACKAGES_ASAN=1
fi
# Build packages
make -j "${MAKE_JOBS}" calculate_package_sha256_sums packages

# Display sha256sums
cat package_sha256sums

# Spot-check shape of library name
version=$(cat VERSION)
filename="dd-library-php-${version}-x86_64-linux-gnu.tar.gz"
if ! [[ -f "build/packages/$filename" ]] ; then
  echo "Expected file 'build/packages/$filename' to exist!"
  exit 1
fi

if ! [[ "$CI_COMMIT_BRANCH" =~ "ddtrace-" ]] ; then
  githash="${CI_COMMIT_SHA?}"
  echo "Non-release branch detected. Checking for git sha1: $githash."
  if echo "$filename" | grep -q "+$githash" ; then
    echo "Confirmed: '$filename' contains git sha1 hash '$githash'."
  else
    echo "'$filename' didn't contain git sha1 hash '$githash'."
    exit 1
  fi
fi

mv build/packages/ packages/
