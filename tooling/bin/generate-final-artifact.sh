#!/usr/bin/env bash

set -xeuo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2

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

    php_apis=(20190902 20200930 20210902 20220829 20230831 20240924)
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        php_apis+=(20151012 20160303 20170718 20180731)
    fi
    for php_api in "${php_apis[@]}"; do
        mkdir -p ${tmp_folder_final_gnu_trace}/ext/$php_api ${tmp_folder_final_musl_trace}/ext/$php_api;
        if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
            ln ./extensions_${architecture}/ddtrace-$php_api.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace.so;
            ln ./extensions_${architecture}/ddtrace-$php_api-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-zts.so;
            ln ./extensions_${architecture}/ddtrace-$php_api-debug.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug.so;
            ln ./extensions_${architecture}/ddtrace-$php_api-alpine.so ${tmp_folder_final_musl_trace}/ext/$php_api/ddtrace.so;
            if [[ ${php_api} -ge 20151012 ]]; then # zts on alpine starting 7.0
                ln ./extensions_${architecture}/ddtrace-$php_api-alpine-zts.so ${tmp_folder_final_musl_trace}/ext/$php_api/ddtrace-zts.so;
            fi
            if [[ ${php_api} -ge 20170718 && $architecture == "x86_64" ]]; then # Windows support starts on 7.2
                mkdir -p ${tmp_folder_final_windows_trace}/ext/$php_api;
                ln ./extensions_windows_${architecture}/php_ddtrace-$php_api.dll ${tmp_folder_final_windows_trace}/ext/$php_api/php_ddtrace.dll;
                ln ./extensions_windows_${architecture}/php_ddtrace-$php_api-zts.dll ${tmp_folder_final_windows_trace}/ext/$php_api/php_ddtrace-zts.dll;
            fi
        else
            ln ./extensions_${architecture}/ddtrace-$php_api-debug-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug-zts.so;
        fi
    done;
    cp -r ./src ${tmp_folder_final_gnu_trace};
    cp -r ./src ${tmp_folder_final_musl_trace};
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} && $architecture == "x86_64" ]]; then
      cp -r ./src ${tmp_folder_final_windows_trace};
    fi

    ########################
    # Profiling
    ########################
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        # Extension
        php_apis=(20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831 20240924)
        for version in "${php_apis[@]}"
        do
            mkdir -v -p \
                $tmp_folder_final_gnu/dd-library-php/profiling/ext/$version \
                $tmp_folder_final_musl/dd-library-php/profiling/ext/$version

            ln -v \
                ./datadog-profiling/$architecture-unknown-linux-gnu/lib/php/$version/datadog-profiling.so \
                $tmp_folder_final_gnu/dd-library-php/profiling/ext/$version/datadog-profiling.so
            ln -v \
                ./datadog-profiling/$architecture-unknown-linux-gnu/lib/php/$version/datadog-profiling-zts.so \
                $tmp_folder_final_gnu/dd-library-php/profiling/ext/$version/datadog-profiling-zts.so

            ln -v \
                ./datadog-profiling/$architecture-alpine-linux-musl/lib/php/$version/datadog-profiling.so \
                $tmp_folder_final_musl/dd-library-php/profiling/ext/$version/datadog-profiling.so
            ln -v \
                ./datadog-profiling/$architecture-alpine-linux-musl/lib/php/$version/datadog-profiling-zts.so \
                $tmp_folder_final_musl/dd-library-php/profiling/ext/$version/datadog-profiling-zts.so
        done

        # Licenses
        ln -v \
            ./profiling/LICENSE* \
            ./profiling/NOTICE \
            $tmp_folder_final_gnu/dd-library-php/profiling/

        ln -v \
            ./profiling/LICENSE* \
            ./profiling/NOTICE \
            $tmp_folder_final_musl/dd-library-php/profiling/
    fi

    ########################
    # AppSec
    ########################
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        tmp_folder_final_gnu_appsec=$tmp_folder_final_gnu/dd-library-php/appsec
        tmp_folder_final_musl_appsec=$tmp_folder_final_musl/dd-library-php/appsec

        # Extensions
        php_apis=(20151012 20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831 20240924);
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

            cp \
                "./appsec_${architecture}/ddappsec-$php_api-alpine-zts.so" \
                "${tmp_folder_final_musl_appsec}/ext/$php_api/ddappsec-zts.so"
        done

        # Helper
        mkdir -p "${tmp_folder_final_gnu_appsec}/lib" "${tmp_folder_final_musl_appsec}/lib"
        cp \
            "./appsec_${architecture}/libddappsec-helper.so" \
            "${tmp_folder_final_gnu_appsec}/lib/libddappsec-helper.so"

        cp \
            "./appsec_${architecture}/libddappsec-helper.so" \
            "${tmp_folder_final_musl_appsec}/lib/libddappsec-helper.so"

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
    # PHP Stubs
    ########################
    $stubs = [
        '/home/circleci/app/src/ddtrace_php_api.stubs.php',
        '/home/circleci/app/ext/ddtrace.stub.php',
        '/home/circleci/app/ext/hook/uhook.stub.php',
        '/home/circleci/app/ext/hook/uhook_attributes.stub.php',
    ];

    $mergedStubs = '';
    foreach ($stubs as $stub) {
        $content = file_get_contents($stub);
        $content = preg_replace('/^<\?php/', '', $content);
        $mergedStubs .= "\n### " . basename($stub) . " ###"
        $merg$mergedStubsed .= $content;
    }

    $stub = "<?php\n" . $mergedStubs;

    echo "$stub" > $tmp_folder_final_gnu/dd-library-php/ddtrace_api.stubs.php
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        echo "$stub" > $tmp_folder_final_musl/dd-library-php/ddtrace_api.stubs.php
        if [[ $architecture == "x86_64" ]]; then
            echo "$stub" > $tmp_folder_final_windows/dd-library-php/ddtrace_api.stubs.php
        fi
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
