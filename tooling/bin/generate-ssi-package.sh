#!/usr/bin/env bash

set -xeuo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2

tmp_folder=/tmp/bundle
tmp_folder_final=$tmp_folder/final

architectures=(x86_64 aarch64)

if [[ -n ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
    exit 0
fi

for architecture in "${architectures[@]}"; do
    root=$tmp_folder_final/$architecture/dd-library-php-ssi
    gnu=$root/linux-gnu
    musl=$root/linux-musl
    trace=$root/trace

    # Starting from a clean folder
    rm -rf ${tmp_folder}
    mkdir -p ${gnu}/loader ${musl}/loader ${trace}

    ########################
    # Loader
    ########################
    cp libddtrace_php_${architecture}.so ${gnu}/loader/libddtrace_php.so
    cp libddtrace_php_${architecture}-alpine.so ${musl}/loader/libddtrace_php.so

    cp dd_library_loader-${architecture}-linux-gnu.so ${gnu}/loader/dd_library_loader.so
    cp dd_library_loader-${architecture}-linux-musl.so ${musl}/loader/dd_library_loader.so

    echo 'zend_extension=${DD_LOADER_PACKAGE_PATH}/linux-gnu/loader/dd_library_loader.so' > ${gnu}/loader/dd_library_loader.ini
    echo 'zend_extension=${DD_LOADER_PACKAGE_PATH}/linux-musl/loader/dd_library_loader.so' > ${musl}/loader/dd_library_loader.ini

    ########################
    # Trace
    ########################

    php_apis=(20151012 20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831)
    for php_api in "${php_apis[@]}"; do
        mkdir -p ${gnu}/trace/ext/$php_api ${musl}/trace/ext/$php_api
        # gnu
        cp ./standalone_${architecture}/ddtrace-$php_api.so ${gnu}/trace/ext/$php_api/ddtrace.so
        cp ./standalone_${architecture}/ddtrace-$php_api-zts.so ${gnu}/trace/ext/$php_api/ddtrace-zts.so
        cp ./standalone_${architecture}/ddtrace-$php_api-debug.so ${gnu}/trace/ext/$php_api/ddtrace-debug.so

        # musl
        cp ./standalone_${architecture}/ddtrace-$php_api-alpine.so ${musl}/trace/ext/$php_api/ddtrace.so
        if [[ ${php_api} -ge 20151012 ]]; then # zts on alpine starting 7.0
            cp ./standalone_${architecture}/ddtrace-$php_api-alpine-zts.so ${musl}/trace/ext/$php_api/ddtrace-zts.so
        fi
    done;

    cp -r ./src ${trace}/
    echo "$release_version" > ${root}/version

    ########################
    # Final archives
    ########################
    tar -czv \
        -f ${packages_build_dir}/dd-library-php-ssi-${release_version}-$architecture-linux.tar.gz \
        -C $tmp_folder_final/$architecture . --owner=0 --group=0
done
