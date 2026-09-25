#!/usr/bin/env bash
set -euo pipefail

MAX_LIBC_VERSION_ALLOWED="${1:-"2.17"}"

function _exit {
    if [[ $? -eq 0 ]]; then
        printf "\nSUCCESS\n"
        exit 0
    else
        printf "\nFAILURE\n"
        exit 1
    fi
}
trap _exit EXIT

if [[ -z "${DD_LOADER_PACKAGE_PATH:-}" ]]; then
    DEFAULT="/home/circleci/app/dd-library-php-ssi"
    echo "Note: env var 'DD_LOADER_PACKAGE_PATH' is required but not defined, using the default value (${DEFAULT})"
    export DD_LOADER_PACKAGE_PATH="${DEFAULT}"
fi

if [[ ! -d "${DD_LOADER_PACKAGE_PATH}" ]]; then
    echo "Package not found at path ${DD_LOADER_PACKAGE_PATH}"
    exit 1
fi

version_less_than_or_equal() {
    #printf 'Testing that %s <= %s\n' "${1}" "${2}"
    if [[ "${1}" == "${2}" ]]; then
        return 1
    fi
    printf '%s\n%s' "${2}" "${1}" | sort -C -V
}

SHARED_OBJECT_LIST=$(find "${DD_LOADER_PACKAGE_PATH}" -name '*.so' -print)
if [[ -z "${SHARED_OBJECT_LIST}" ]]; then
    echo "Package contains no shared objects: ${DD_LOADER_PACKAGE_PATH}"
    exit 1
fi
mapfile -t SHARED_OBJECTS <<< "${SHARED_OBJECT_LIST}"

if ! VERSION_INFO=$(objdump -T "${SHARED_OBJECTS[@]}" 2>/dev/null); then
    echo "Failed to inspect the package's shared objects"
    exit 1
fi
PACKAGE_MAX=$(
    sed -nE 's/.*GLIBC_([^ )]+).*/\1/p' <<< "${VERSION_INFO}" |
        sort -V |
        tail -n 1
)
if [[ -z "${PACKAGE_MAX}" ]]; then
    echo "All good! The package has no versioned glibc symbols."
elif version_less_than_or_equal "${PACKAGE_MAX}" "${MAX_LIBC_VERSION_ALLOWED}"; then
    echo "Error. The max glibc version allowed is '${MAX_LIBC_VERSION_ALLOWED}', but the package requires '${PACKAGE_MAX}'."
    echo "If you cannot lower the glibc version, you must update the glibc condition in the auto-injector (https://github.com/DataDog/auto_inject)"
    exit 1
else
    echo "All good! The max glibc version allowed is '${MAX_LIBC_VERSION_ALLOWED}', and the package requires '${PACKAGE_MAX}'."
fi
