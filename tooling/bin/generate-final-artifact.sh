#!/usr/bin/env bash

set -euo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2
profiling_url=$3

tmp_folder=/tmp/bundle
tmp_folder_final=$tmp_folder/final
tmp_folder_final_gnu=$tmp_folder_final/x86_64-linux-gnu
tmp_folder_final_musl=$tmp_folder_final/x86_64-linux-musl

# Starting from a clean folder
rm -rf $tmp_folder
mkdir -p $tmp_folder_final_gnu
mkdir -p $tmp_folder_final_musl

########################
# Trace
########################
tmp_folder_trace=$tmp_folder/trace
mkdir -p $tmp_folder_trace
tmp_folder_final_gnu_trace=$tmp_folder_final_gnu/dd-library-php/trace
tmp_folder_final_musl_trace=$tmp_folder_final_musl/dd-library-php/trace

php_apis=(20100412 20121113 20131106 20151012 20160303 20170718 20180731 20190902 20200930 20210902);
for php_api in "${php_apis[@]}"; do
    mkdir -p ${tmp_folder_final_gnu_trace}/ext/$php_api ${tmp_folder_final_musl_trace}/ext/$php_api;
    cp ./extensions/ddtrace-$php_api.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace.so;
    cp ./extensions/ddtrace-$php_api-zts.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-zts.so;
    cp ./extensions/ddtrace-$php_api-debug.so ${tmp_folder_final_gnu_trace}/ext/$php_api/ddtrace-debug.so;
    cp ./extensions/ddtrace-$php_api-alpine.so ${tmp_folder_final_musl_trace}/ext/$php_api/ddtrace.so;
done;
cp -r ./bridge ${tmp_folder_final_gnu_trace};
cp -r ./bridge ${tmp_folder_final_musl_trace};

########################
# Profiling
########################
tmp_folder_profiling=$tmp_folder/profiling
tmp_folder_profiling_archive=$tmp_folder_profiling/datadog-profiling.tar.gz
mkdir -p $tmp_folder_profiling
curl -L -o $tmp_folder_profiling_archive $profiling_url
tar -xf $tmp_folder_profiling_archive -C $tmp_folder_profiling

# Extension
php_apis=(20160303 20170718 20180731 20190902 20200930)
for version in "${php_apis[@]}"
do
    mkdir -v -p \
        $tmp_folder_final/x86_64-linux-gnu/dd-library-php/profiling/ext/$version \
        $tmp_folder_final/x86_64-linux-musl/dd-library-php/profiling/ext/$version

    cp -v \
        $tmp_folder_profiling/datadog-profiling/x86_64-unknown-linux-gnu/lib/php/$version/datadog-profiling.so \
        $tmp_folder_final/x86_64-linux-gnu/dd-library-php/profiling/ext/$version/datadog-profiling.so

    cp -v \
        $tmp_folder_profiling/datadog-profiling/x86_64-alpine-linux-musl/lib/php/$version/datadog-profiling.so \
        $tmp_folder_final/x86_64-linux-musl/dd-library-php/profiling/ext/$version/datadog-profiling.so
done

# Licenses
cp -v \
    $tmp_folder_profiling/datadog-profiling/x86_64-unknown-linux-gnu/LICENSE* \
    $tmp_folder_profiling/datadog-profiling/x86_64-unknown-linux-gnu/NOTICE* \
    $tmp_folder_final_gnu/dd-library-php/profiling/

cp -v \
    $tmp_folder_profiling/datadog-profiling/x86_64-alpine-linux-musl/LICENSE* \
    $tmp_folder_profiling/datadog-profiling/x86_64-alpine-linux-musl/NOTICE* \
    $tmp_folder_final_musl/dd-library-php/profiling/

########################
# Final archives
########################
echo "$release_version" > ${tmp_folder_final_gnu}/dd-library-php/VERSION
tar -czv \
    -f ${packages_build_dir}/dd-library-php-x86_64-linux-gnu.tar.gz \
    -C ${tmp_folder_final_gnu} .

echo "$release_version" > ${tmp_folder_final_musl}/dd-library-php/VERSION
tar -czv \
    -f ${packages_build_dir}/dd-library-php-x86_64-linux-musl.tar.gz \
    -C ${tmp_folder_final_musl} .
