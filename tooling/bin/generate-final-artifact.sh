#!/usr/bin/env bash

set -euo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2
profiling_url=$3
appsec_url=$4

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

php_apis=(20100412 20121113 20131106);
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
# Final archives
########################
echo "$release_version" > ${tmp_folder_final_gnu}/dd-library-php/VERSION
tar -czv \
    -f ${packages_build_dir}/dd-library-php-${release_version}-x86_64-linux-gnu.tar.gz \
    -C ${tmp_folder_final_gnu} . --owner=0 --group=0

echo "$release_version" > ${tmp_folder_final_musl}/dd-library-php/VERSION
tar -czv \
    -f ${packages_build_dir}/dd-library-php-${release_version}-x86_64-linux-musl.tar.gz \
    -C ${tmp_folder_final_musl} . --owner=0 --group=0
