#!/usr/bin/env bash

set -xeuo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2

# This string will be used in the OCI tag
# '+' char is not allowed
release_version_sanitized=${release_version/+/-}

tmp_folder=/tmp/ssi-bundle
tmp_folder_final=$tmp_folder/final

architectures=(x86_64 aarch64)

if [[ -n ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
    exit 0
fi

function stripto() {
    source=$1
    target=$2

    local arch_cmd_prefix=""
    if [[ "${architecture}" == "aarch64" ]]; then
        arch_cmd_prefix="aarch64-linux-gnu-"
    fi

    "${arch_cmd_prefix}objcopy" --only-keep-debug "$source" "${target}.debug"
    "${arch_cmd_prefix}strip" -o "$target" "$source"
    (
        cd "$(dirname "$target")"
        filename=$(basename "$target")
        "${arch_cmd_prefix}objcopy" --add-gnu-debuglink="${filename}.debug" "${filename}"
    )
}

for architecture in "${architectures[@]}"; do
    root=$tmp_folder_final/$architecture/dd-library-php-ssi
    gnu=$root/linux-gnu
    musl=$root/linux-musl

    # Starting from a clean folder
    rm -rf ${tmp_folder}

    ########################
    # Loader
    ########################

    mkdir -p ${gnu}/loader ${musl}/loader

    stripto libddtrace_php_${architecture}.so ${gnu}/loader/libddtrace_php.so
    stripto libddtrace_php_${architecture}-alpine.so ${musl}/loader/libddtrace_php.so

    stripto dd_library_loader-${architecture}-linux-gnu.so ${gnu}/loader/dd_library_loader.so
    stripto dd_library_loader-${architecture}-linux-musl.so ${musl}/loader/dd_library_loader.so

    echo 'zend_extension=${DD_LOADER_PACKAGE_PATH}/linux-gnu/loader/dd_library_loader.so' > ${gnu}/loader/dd_library_loader.ini
    echo 'zend_extension=${DD_LOADER_PACKAGE_PATH}/linux-musl/loader/dd_library_loader.so' > ${musl}/loader/dd_library_loader.ini

    ########################
    # Products
    ########################

    php_apis=(20151012 20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831 20240924)
    for php_api in "${php_apis[@]}"; do
        ########################
        # Trace
        ########################

        mkdir -p ${gnu}/trace/ext/${php_api} ${musl}/trace/ext/${php_api}
        # gnu
        stripto ./standalone_${architecture}/ddtrace-${php_api}.so ${gnu}/trace/ext/${php_api}/ddtrace.so
        stripto ./standalone_${architecture}/ddtrace-${php_api}-zts.so ${gnu}/trace/ext/${php_api}/ddtrace-zts.so
        # musl
        stripto ./standalone_${architecture}/ddtrace-${php_api}-alpine.so ${musl}/trace/ext/${php_api}/ddtrace.so
        stripto ./standalone_${architecture}/ddtrace-${php_api}-alpine-zts.so ${musl}/trace/ext/${php_api}/ddtrace-zts.so

        ########################
        # Profiling
        ########################

        if [[ ${php_api} -ge 20160303 ]]; then
            mkdir -p ${gnu}/profiling/ext/${php_api} ${musl}/profiling/ext/${php_api}
            # gnu
            stripto ./datadog-profiling/${architecture}-unknown-linux-gnu/lib/php/${php_api}/datadog-profiling.so \
                ${gnu}/profiling/ext/${php_api}/datadog-profiling.so
            stripto ./datadog-profiling/${architecture}-unknown-linux-gnu/lib/php/${php_api}/datadog-profiling-zts.so \
                ${gnu}/profiling/ext/${php_api}/datadog-profiling-zts.so
            # musl
            stripto ./datadog-profiling/${architecture}-alpine-linux-musl/lib/php/${php_api}/datadog-profiling.so \
                ${musl}/profiling/ext/${php_api}/datadog-profiling.so
            stripto ./datadog-profiling/${architecture}-alpine-linux-musl/lib/php/${php_api}/datadog-profiling-zts.so \
                ${musl}/profiling/ext/${php_api}/datadog-profiling-zts.so
        fi

        ########################
        # AppSec
        ########################

        mkdir -p ${gnu}/appsec/ext/${php_api} ${musl}/appsec/ext/${php_api}
        # gnu
        stripto ./appsec_${architecture}/ddappsec-${php_api}.so ${gnu}/appsec/ext/${php_api}/ddappsec.so
        stripto ./appsec_${architecture}/ddappsec-${php_api}-zts.so ${gnu}/appsec/ext/${php_api}/ddappsec-zts.so
        # musl
        stripto ./appsec_${architecture}/ddappsec-${php_api}-alpine.so ${musl}/appsec/ext/${php_api}/ddappsec.so
        stripto ./appsec_${architecture}/ddappsec-${php_api}-alpine-zts.so ${musl}/appsec/ext/${php_api}/ddappsec-zts.so
    done

    # Trace
    mkdir -p "${root}/trace"
    cp -r ./src "${root}/trace/"

    # AppSec
    mkdir -p "${root}/appsec/lib" "${root}/appsec/etc"
    ln "./appsec_${architecture}/libddappsec-helper.so" "${root}/appsec/lib/libddappsec-helper.so"
    ln "./appsec_${architecture}/recommended.json"  "${root}/appsec/etc/recommended.json"

    ########################
    # Final archives
    ########################

    echo "$release_version_sanitized" > ${root}/version
    ln ./loader/packaging/requirements.json ${root}/requirements.json

    tar -czv \
        -f ${packages_build_dir}/dd-library-php-ssi-${release_version}-$architecture-linux.tar.gz \
        -C $tmp_folder_final/$architecture . --owner=0 --group=0
done
