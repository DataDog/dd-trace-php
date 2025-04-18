#!/usr/bin/env bash

set -xeuo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2

mkdir -p $packages_build_dir

tmp_folder=${CI_PROJECT_DIR:-.}/tmp/bundle
tmp_folder_final=$tmp_folder/final

architectures=(x86_64 aarch64)

php_apis=(20190902 20200930 20210902 20220829 20230831 20240924)
if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
    php_apis+=(20151012 20160303 20170718 20180731)
fi

targets=(unknown-linux-gnu alpine-linux-musl)
if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
    targets+=(windows)
fi

# if TRIPLET env var is set, then parse it to get the architectures and targets
# Example: x86_64-unknown-linux-gnu, aarch64-alpine-linux-musl, etc
if [[ -n ${TRIPLET:-} ]]; then
    architectures=()
    targets=()

    if [[ $TRIPLET = "x86_64-pc-windows-msvc" ]]; then
        architectures+=("x86_64")
        targets+=("windows")
    else
        IFS='-' read -r arch target <<< "$TRIPLET"
        architectures+=("$arch")
        targets+=("$target")
    fi
fi


configs=("" -zts -debug -debug-zts)


cp_with_dir() {
    mkdir -p $(dirname $2)
    cp $1 $2
}

echo "Architectures: ${architectures[@]}"
echo "Targets: ${targets[@]}"
echo "PHP APIs: ${php_apis[@]}"
echo "Configs: ${configs[@]}"

for architecture in "${architectures[@]}"; do
    for php_api in "${php_apis[@]}"; do
        for full_target in "${targets[@]}"; do
            target=${full_target#*-}
            alpine=$(if [[ $target == "linux-musl" ]]; then echo -alpine; fi)
            ext=$([[ $target == "windows" ]] && echo dll || echo so)
            for config in "${configs[@]}"; do
                ddtrace_ext_path=./extensions_${architecture}/$(if [[ $target == "windows" ]]; then echo php_; fi)ddtrace-${php_api}${alpine}${config}.${ext}
                if [[ -f ${ddtrace_ext_path} ]]; then
                    rm -rf $tmp_folder
                    mkdir -p $tmp_folder_final

                    trace_base_dir=${tmp_folder_final}/dd-library-php/trace
                    cp_with_dir ${ddtrace_ext_path} ${trace_base_dir}/ext/${php_api}/$(if [[ $target == "windows" ]]; then echo php_; fi)ddtrace${config}.${ext}
                    cp -r ./src ${trace_base_dir}

                    profiling_ext_path=./datadog-profiling/${architecture}-${full_target}/lib/php/${php_api}/datadog-profiling${config}.${ext}
                    if [[ -f ${profiling_ext_path} ]]; then
                        profiling_base_dir=${tmp_folder_final}/dd-library-php/profiling
                        cp_with_dir ${profiling_ext_path} ${profiling_base_dir}/ext/${php_api}/datadog-profiling${config}.${ext}

                        # Licenses
                        cp \
                            ./profiling/LICENSE* \
                            ./profiling/NOTICE \
                            ${profiling_base_dir}/
                    fi

                    appsec_ext_path=./appsec_${architecture}/ddappsec-${php_api}${alpine}${config}.${ext}
                    if [[ -f ${appsec_ext_path} ]]; then
                        appsec_base_dir=${tmp_folder_final}/dd-library-php/appsec
                        cp_with_dir ${appsec_ext_path} ${appsec_base_dir}/ext/$php_api/ddappsec${config}.${ext}
                        cp_with_dir ./appsec_${architecture}/libddappsec-helper.so ${appsec_base_dir}/lib/libddappsec-helper.so
                        cp_with_dir ./appsec_${architecture}/recommended.json ${appsec_base_dir}/etc/recommended.json
                    fi

                    echo "$release_version" > ${tmp_folder_final}/dd-library-php/VERSION
                    tar -czv \
                        -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-$target-${php_api}${config}.tar.gz \
                        -C ${tmp_folder_final} . --owner=0 --group=0
                fi
            done
        done
    done
done

for architecture in "${architectures[@]}"; do
    tmp_folder_final_gnu=$tmp_folder_final/$architecture-linux-gnu
    tmp_folder_final_musl=$tmp_folder_final/$architecture-linux-musl
    tmp_folder_final_windows=$tmp_folder_final/$architecture-windows

    # Starting from a clean folder
    rm -rf $tmp_folder
    for full_target in "${targets[@]}"; do
        target=${full_target#*-}
        if [[ $target == "linux-gnu" ]]; then
            mkdir -p $tmp_folder_final_gnu
        fi
        if [[ $target == "linux-musl" ]]; then
            mkdir -p $tmp_folder_final_musl
        fi
        if [[ $target == "windows" && $architecture == "x86_64" ]]; then
            mkdir -p $tmp_folder_final_windows
        fi
    done

    ########################
    # Trace
    ########################
    tmp_folder_trace=$tmp_folder/trace
    mkdir -p $tmp_folder_trace
    tmp_folder_final_gnu_trace=$tmp_folder_final_gnu/dd-library-php/trace
    tmp_folder_final_musl_trace=$tmp_folder_final_musl/dd-library-php/trace
    tmp_folder_final_windows_trace=$tmp_folder_final_windows/dd-library-php/trace

    for php_api in "${php_apis[@]}"; do
        for full_target in "${targets[@]}"; do
            target=${full_target#*-}

            if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
                if [[ $target == "linux-gnu" ]]; then
                    mkdir -p ${tmp_folder_final_gnu_trace}/ext/$php_api
                    cp ./extensions_${architecture}/ddtrace-$php_api.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace.so;
                    cp ./extensions_${architecture}/ddtrace-$php_api-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-zts.so;
                    cp ./extensions_${architecture}/ddtrace-$php_api-debug.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug.so;
                fi
                if [[ $target == "linux-musl" ]]; then
                    mkdir -p ${tmp_folder_final_musl_trace}/ext/$php_api;
                    cp ./extensions_${architecture}/ddtrace-$php_api-alpine.so ${tmp_folder_final_musl_trace}/ext/$php_api/ddtrace.so;
                    cp ./extensions_${architecture}/ddtrace-$php_api-alpine-zts.so ${tmp_folder_final_musl_trace}/ext/$php_api/ddtrace-zts.so;
                fi
                if [[ $target == "windows" && ${php_api} -ge 20170718 && $architecture == "x86_64" ]]; then # Windows support starts on 7.2
                    mkdir -p ${tmp_folder_final_windows_trace}/ext/$php_api;
                    cp ./extensions_${architecture}/php_ddtrace-$php_api.dll ${tmp_folder_final_windows_trace}/ext/$php_api/php_ddtrace.dll;
                    cp ./extensions_${architecture}/php_ddtrace-$php_api-zts.dll ${tmp_folder_final_windows_trace}/ext/$php_api/php_ddtrace-zts.dll;
                fi
            else
                ls ./extensions_${architecture}/
                cp ./extensions_${architecture}/ddtrace-$php_api-debug-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug-zts.so;
            fi
        done;
    done;

    for full_target in "${targets[@]}"; do
        target=${full_target#*-}

        if [[ $target == "linux-gnu" ]]; then
            cp -r ./src ${tmp_folder_final_gnu_trace};
        fi

        if [[ $target == "linux-musl" ]]; then
            cp -r ./src ${tmp_folder_final_musl_trace};
        fi

        if [[ $target == "windows" && $architecture == "x86_64" ]]; then
            cp -r ./src ${tmp_folder_final_windows_trace};
        fi
    done

    ########################
    # Profiling
    ########################
    if [[ -z ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
        # Extension
        php_apis=(20160303 20170718 20180731 20190902 20200930 20210902 20220829 20230831 20240924)
        for version in "${php_apis[@]}"
        do
            for full_target in "${targets[@]}"; do
                target=${full_target#*-}
                if [[ $target == "linux-gnu" ]]; then
                    mkdir -v -p \
                          $tmp_folder_final_gnu/dd-library-php/profiling/ext/$version

                    cp -v \
                        ./datadog-profiling/$architecture-unknown-linux-gnu/lib/php/$version/datadog-profiling.so \
                        $tmp_folder_final_gnu/dd-library-php/profiling/ext/$version/datadog-profiling.so
                    cp -v \
                        ./datadog-profiling/$architecture-unknown-linux-gnu/lib/php/$version/datadog-profiling-zts.so \
                        $tmp_folder_final_gnu/dd-library-php/profiling/ext/$version/datadog-profiling-zts.so
                fi

                if [[ $target == "linux-musl" ]]; then
                    mkdir -v -p \
                          $tmp_folder_final_musl/dd-library-php/profiling/ext/$version

                    cp -v \
                        ./datadog-profiling/$architecture-alpine-linux-musl/lib/php/$version/datadog-profiling.so \
                        $tmp_folder_final_musl/dd-library-php/profiling/ext/$version/datadog-profiling.so
                    cp -v \
                        ./datadog-profiling/$architecture-alpine-linux-musl/lib/php/$version/datadog-profiling-zts.so \
                        $tmp_folder_final_musl/dd-library-php/profiling/ext/$version/datadog-profiling-zts.so
                fi
            done
        done

        # Licenses
        for full_target in "${targets[@]}"; do
            target=${full_target#*-}
            if [[ $target == "linux-gnu" ]]; then
                cp -v \
                    ./profiling/LICENSE* \
                    ./profiling/NOTICE \
                    $tmp_folder_final_gnu/dd-library-php/profiling/
            fi
            if [[ $target == "linux-musl" ]]; then
                cp -v \
                    ./profiling/LICENSE* \
                    ./profiling/NOTICE \
                    $tmp_folder_final_musl/dd-library-php/profiling/
           fi
        done
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
            for full_target in "${targets[@]}"; do
                target=${full_target#*-}

                if [[ $target == "linux-gnu" ]]; then
                    mkdir -p ${tmp_folder_final_gnu_appsec}/ext/$php_api

                    cp \
                        "./appsec_${architecture}/ddappsec-$php_api.so" \
                        "${tmp_folder_final_gnu_appsec}/ext/$php_api/ddappsec.so"

                    cp \
                        "./appsec_${architecture}/ddappsec-$php_api-zts.so" \
                        "${tmp_folder_final_gnu_appsec}/ext/$php_api/ddappsec-zts.so"
                fi

                if [[ $target == "linux-musl" ]]; then
                    mkdir -p ${tmp_folder_final_musl_appsec}/ext/$php_api

                    cp \
                        "./appsec_${architecture}/ddappsec-$php_api-alpine.so" \
                        "${tmp_folder_final_musl_appsec}/ext/$php_api/ddappsec.so"

                    cp \
                        "./appsec_${architecture}/ddappsec-$php_api-alpine-zts.so" \
                        "${tmp_folder_final_musl_appsec}/ext/$php_api/ddappsec-zts.so"
                fi
            done
        done

        # Helper + Recommended rules
        for full_target in "${targets[@]}"; do
            target=${full_target#*-}

            if [[ $target == "linux-gnu" ]]; then
                mkdir -p "${tmp_folder_final_gnu_appsec}/lib"
                mkdir -p "${tmp_folder_final_gnu_appsec}/etc"
                cp \
                    "./appsec_${architecture}/libddappsec-helper.so" \
                    "${tmp_folder_final_gnu_appsec}/lib/libddappsec-helper.so"
                cp \
                    "./appsec_${architecture}/recommended.json" \
                    "${tmp_folder_final_gnu_appsec}/etc/recommended.json"
            fi

            if [[ $target == "linux-musl" ]]; then
                mkdir -p "${tmp_folder_final_musl_appsec}/lib"
                mkdir -p "${tmp_folder_final_musl_appsec}/etc"
                cp \
                    "./appsec_${architecture}/libddappsec-helper.so" \
                    "${tmp_folder_final_musl_appsec}/lib/libddappsec-helper.so"
                cp \
                    "./appsec_${architecture}/recommended.json" \
                    "${tmp_folder_final_musl_appsec}/etc/recommended.json"
            fi
        done
    fi

    ########################
    # PHP Stubs
    ########################
    stubs=(
        "$3/src/ddtrace_php_api.stubs.php"
        "$3/ext/ddtrace.stub.php"
        "$3/ext/hook/uhook.stub.php"
        "$3/ext/hook/uhook_attributes.stub.php"
    )

    mergedStubs=""
    for stub in "${stubs[@]}"; do
        content=$(<"$stub")
        content="${content#<?php}"
        mergedStubs+="$content"
    done

    stub=$'<?php\n'"$mergedStubs"
    echo "$stub" > "$packages_build_dir/datadog-tracer.stubs.php"

    ########################
    # Final archives
    ########################
    for full_target in "${targets[@]}"; do
        target=${full_target#*-}
        if [[ $target == "linux-gnu" ]]; then
            echo "$release_version" > ${tmp_folder_final_gnu}/dd-library-php/VERSION
            tar -czv \
                -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-linux-gnu.tar.gz \
                -C ${tmp_folder_final_gnu} . --owner=0 --group=0
        fi
        if [[ $target == "linux-musl" ]]; then
            echo "$release_version" > ${tmp_folder_final_musl}/dd-library-php/VERSION
            tar -czv \
                -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-linux-musl.tar.gz \
                -C ${tmp_folder_final_musl} . --owner=0 --group=0
            cp -v ${packages_build_dir}/datadog-tracer.stubs.php ${tmp_folder_final_musl}/dd-library-php/
        fi
        if [[ $target == "windows" ]]; then
            if [[ $architecture == "x86_64" ]]; then
                echo "$release_version" > ${tmp_folder_final_windows}/dd-library-php/VERSION
                tar -czv \
                    -f ${packages_build_dir}/dd-library-php-${release_version}-$architecture-windows.tar.gz \
                    -C ${tmp_folder_final_windows} . --owner=0 --group=0
            fi
        fi
    done

done
