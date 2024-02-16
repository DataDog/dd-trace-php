#!/usr/bin/env bash

set -xeuo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2
profiling_url=$3

tmp_folder=/tmp/bundle
tmp_folder_final=$tmp_folder/final

architectures=(x86_64 aarch64)

for architecture in "${architectures[@]}"; do
    tmp_folder_final_gnu=$tmp_folder_final/$architecture-linux-gnu
    tmp_folder_final_musl=$tmp_folder_final/$architecture-linux-musl
    tmp_folder_final_windows=$tmp_folder_final/$architecture-windows

    # Starting from a clean folder
    rm -rf $tmp_folder
    mkdir -p $tmp_folder_final_gnu
    mkdir -p $tmp_folder_final_musl
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} && $architecture == "x86_64" ]]; then
      mkdir -p $tmp_folder_final_windows
    fi

    ########################
    # Trace
    ########################
    tmp_folder_trace=$tmp_folder/trace
    mkdir -p $tmp_folder_trace
    tmp_folder_final_gnu_trace=$tmp_folder_final_gnu/dd-library-php/trace
    tmp_folder_final_musl_trace=$tmp_folder_final_musl/dd-library-php/trace
    tmp_folder_final_windows_trace=$tmp_folder_final_windows/dd-library-php/trace

    php_apis=(20190902 20200930 20210902 20220829 20230831)
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        php_apis+=(20151012 20160303 20170718 20180731)
        if [[ $architecture == "x86_64" ]]; then
            php_apis+=(20100412 20121113 20131106)
        fi
    fi
    for php_api in "${php_apis[@]}"; do
        mkdir -p ${tmp_folder_final_gnu_trace}/ext/$php_api ${tmp_folder_final_musl_trace}/ext/$php_api;
        if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
            cp ./extensions_${architecture}/ddtrace-$php_api.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace.so;
            cp ./extensions_${architecture}/ddtrace-$php_api-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-zts.so;
            cp ./extensions_${architecture}/ddtrace-$php_api-debug.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug.so;
            cp ./extensions_${architecture}/ddtrace-$php_api-alpine.so ${tmp_folder_final_musl_trace}/ext/$php_api/ddtrace.so;
            if [[ ${php_api} -ge 20170718 && $architecture == "x86_64" ]]; then # Windows support starts on 7.2
                mkdir -p ${tmp_folder_final_windows_trace}/ext/$php_api;
                cp ./extensions_windows_${architecture}/php_ddtrace-$php_api.dll ${tmp_folder_final_windows_trace}/ext/$php_api/php_ddtrace.dll;
                cp ./extensions_windows_${architecture}/php_ddtrace-$php_api-zts.dll ${tmp_folder_final_windows_trace}/ext/$php_api/php_ddtrace-zts.dll;
            fi
        else
            cp ./extensions_${architecture}/ddtrace-$php_api-debug-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug-zts.so;
        fi
    done;
    cp -r ./src ${tmp_folder_final_gnu_trace};
    cp -r ./bridge ${tmp_folder_final_gnu_trace};
    cp -r ./src ${tmp_folder_final_musl_trace};
    cp -r ./bridge ${tmp_folder_final_musl_trace};
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} && $architecture == "x86_64" ]]; then
      cp -r ./bridge ${tmp_folder_final_windows_trace};
    fi

    ########################
    # Profiling
    ########################
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        tmp_folder_profiling=$tmp_folder/profiling
        tmp_folder_profiling_archive=$tmp_folder_profiling/datadog-profiling.tar.gz
        mkdir -p $tmp_folder_profiling

        # Profiling: C version
        #curl -L -o $tmp_folder_profiling_archive $profiling_url

        # Profiling: Rust version
        cp -v datadog-profiling.tar.gz "$tmp_folder_profiling_archive"

        tar -xf $tmp_folder_profiling_archive -C $tmp_folder_profiling

        # Extension
        php_apis=(20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831)
        for version in "${php_apis[@]}"
        do
            mkdir -v -p \
                $tmp_folder_final/$architecture-linux-gnu/dd-library-php/profiling/ext/$version \
                $tmp_folder_final/$architecture-linux-musl/dd-library-php/profiling/ext/$version

            cp -v \
                $tmp_folder_profiling/datadog-profiling/$architecture-unknown-linux-gnu/lib/php/$version/datadog-profiling.so \
                $tmp_folder_final/$architecture-linux-gnu/dd-library-php/profiling/ext/$version/datadog-profiling.so
            cp -v \
                $tmp_folder_profiling/datadog-profiling/$architecture-unknown-linux-gnu/lib/php/$version/datadog-profiling-zts.so \
                $tmp_folder_final/$architecture-linux-gnu/dd-library-php/profiling/ext/$version/datadog-profiling-zts.so

            cp -v \
                $tmp_folder_profiling/datadog-profiling/$architecture-alpine-linux-musl/lib/php/$version/datadog-profiling.so \
                $tmp_folder_final/$architecture-linux-musl/dd-library-php/profiling/ext/$version/datadog-profiling.so
            cp -v \
                $tmp_folder_profiling/datadog-profiling/$architecture-alpine-linux-musl/lib/php/$version/datadog-profiling-zts.so \
                $tmp_folder_final/$architecture-linux-musl/dd-library-php/profiling/ext/$version/datadog-profiling-zts.so
        done

        # Licenses
        cp -v \
            $tmp_folder_profiling/datadog-profiling/$architecture-unknown-linux-gnu/LICENSE* \
            $tmp_folder_profiling/datadog-profiling/$architecture-unknown-linux-gnu/NOTICE* \
            $tmp_folder_final_gnu/dd-library-php/profiling/

        cp -v \
            $tmp_folder_profiling/datadog-profiling/$architecture-alpine-linux-musl/LICENSE* \
            $tmp_folder_profiling/datadog-profiling/$architecture-alpine-linux-musl/NOTICE* \
            $tmp_folder_final_musl/dd-library-php/profiling/
    fi

    ########################
    # AppSec
    ########################
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        tmp_folder_final_gnu_appsec=$tmp_folder_final_gnu/dd-library-php/appsec
        tmp_folder_final_musl_appsec=$tmp_folder_final_musl/dd-library-php/appsec

        # Extensions
        php_apis=(20151012 20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831);
        for php_api in "${php_apis[@]}"; do
            mkdir -p \
                ${tmp_folder_final_gnu_appsec}/ext/$php_api \
                ${tmp_folder_final_musl_appsec}/ext/$php_api

            cp \
                "./appsec_${architecture}/ddappsec-$php_api.so" \
                "${tmp_folder_final_gnu_appsec}/ext/$php_api/ddappsec.so"

            cp \
                "./appsec_${architecture}/ddappsec-$php_api-zts.so" \
                "${tmp_folder_final_gnu_appsec}/ext/$php_api/ddappsec-zts.so"

            cp \
                "./appsec_${architecture}/ddappsec-$php_api-alpine.so" \
                "${tmp_folder_final_musl_appsec}/ext/$php_api/ddappsec.so"
        done

        # Helper
        mkdir -p "${tmp_folder_final_gnu_appsec}/bin" "${tmp_folder_final_musl_appsec}/bin"
        cp \
            "./appsec_${architecture}/ddappsec-helper" \
            "${tmp_folder_final_gnu_appsec}/bin/ddappsec-helper"

        cp \
            "./appsec_${architecture}/ddappsec-helper" \
            "${tmp_folder_final_musl_appsec}/bin/ddappsec-helper"

        # Recommended rules
        mkdir -p "${tmp_folder_final_gnu_appsec}/etc" "${tmp_folder_final_musl_appsec}/etc"
        cp \
            "./appsec_${architecture}/recommended.json" \
            "${tmp_folder_final_gnu_appsec}/etc/recommended.json"
        cp \
            "./appsec_${architecture}/recommended.json" \
            "${tmp_folder_final_musl_appsec}/etc/recommended.json"
    fi

    ########################
    # Final archives
    ########################
    echo "$release_version" > ${tmp_folder_final_gnu}/dd-library-php/VERSION
    tar -czv \
        -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-linux-gnu.tar.gz \
        -C ${tmp_folder_final_gnu} . --owner=0 --group=0

    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        echo "$release_version" > ${tmp_folder_final_musl}/dd-library-php/VERSION
        tar -czv \
            -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-linux-musl.tar.gz \
            -C ${tmp_folder_final_musl} . --owner=0 --group=0

        if [[ $architecture == "x86_64" ]]; then
            echo "$release_version" > ${tmp_folder_final_windows}/dd-library-php/VERSION
            tar -czv \
                -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-windows.tar.gz \
                -C ${tmp_folder_final_windows} . --owner=0 --group=0
        fi
    fi
done
