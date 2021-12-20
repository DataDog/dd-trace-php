#!/usr/bin/env bash

set -euo pipefail
IFS=$'\n\t'

release_version=$1
trace_gnu_url=$2
trace_musl_url=$3
profiling_url=$4

if [ -z "$(echo $trace_gnu_url | grep gnu)" ]; then
    echo "GNU url $trace_gnu_url has not the word 'gnu'."
    exit 1
fi

if [ -z "$(echo $trace_musl_url | grep musl)" ]; then
    echo "MUSL url $trace_musl_url has not the word 'musl'."
    exit 1
fi

tmp_folder=/tmp/dd-library-php
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
tmp_folder_trace_archive_gnu=$tmp_folder_trace/dd-library-php-x86_64-linux-gnu.tar.gz
tmp_folder_trace_archive_musl=$tmp_folder_trace/dd-library-php-x86_64-linux-gnu.tar.gz
mkdir -p $tmp_folder_trace
curl -L -o $tmp_folder_trace_archive_gnu $trace_gnu_url
tar -xf $tmp_folder_trace_archive_gnu -C $tmp_folder_final_gnu
curl -L -o $tmp_folder_trace_archive_musl $trace_musl_url
tar -xf $tmp_folder_trace_archive_musl -C $tmp_folder_final_musl

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
    mkdir -p $tmp_folder_final/x86_64-linux-gnu/dd-library-php/profiling/ext/$version $tmp_folder_final/x86_64-linux-musl/dd-library-php/profiling/ext/$version
    cp $tmp_folder_profiling/datadog-profiling/x86_64-linux-gnu/lib/php/$version/datadog-profiling.so $tmp_folder_final/x86_64-linux-gnu/dd-library-php/profiling/ext/$version/datadog-profiling.so
    cp $tmp_folder_profiling/datadog-profiling/x86_64-linux-musl/lib/php/$version/datadog-profiling.so $tmp_folder_final/x86_64-linux-musl/dd-library-php/profiling/ext/$version/datadog-profiling.so
done

# Licenses
cp \
    $tmp_folder_profiling/datadog-profiling/x86_64-linux-gnu/LICENSE* \
    $tmp_folder_profiling/datadog-profiling/x86_64-linux-gnu/NOTICE* \
    $tmp_folder_final_gnu/dd-library-php/profiling
cp \
    $tmp_folder_profiling/datadog-profiling/x86_64-linux-musl/LICENSE* \
    $tmp_folder_profiling/datadog-profiling/x86_64-linux-musl/NOTICE* \
    $tmp_folder_final_musl/dd-library-php/profiling

########################
# Final archives
########################
echo "$release_version" > /tmp/dd-library-php/final/x86_64-linux-gnu/dd-library-php/VERSION
tar -czvf dd-library-php-x86_64-linux-gnu.tar.gz -C /tmp/dd-library-php/final/x86_64-linux-gnu .

echo "$release_version" > /tmp/dd-library-php/final/x86_64-linux-musl/dd-library-php/VERSION
tar -czvf dd-library-php-x86_64-linux-musl.tar.gz -C /tmp/dd-library-php/final/x86_64-linux-musl .
