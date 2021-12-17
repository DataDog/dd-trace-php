#!/usr/bin/env bash

set -euo pipefail
IFS=$'\n\t'

release_version=$1
tracer_gnu_url=$2
tracer_musl_url=$3
profiler_url=$4

if [ -z "$(echo $tracer_gnu_url | grep gnu)" ]; then
    echo "GNU url $tracer_gnu_url has not the word 'gnu'."
    exit 1
fi

if [ -z "$(echo $tracer_musl_url | grep musl)" ]; then
    echo "MUSL url $tracer_musl_url has not the word 'musl'."
    exit 1
fi

tmp_folder=/tmp/dd-library-php
tmp_folder_final=$tmp_folder/final
tmp_folder_final_gnu=$tmp_folder_final/x86_64-gnu
tmp_folder_final_musl=$tmp_folder_final/x86_64-musl

# Starting from a clean folder
rm -rf $tmp_folder
mkdir -p $tmp_folder_final_gnu
mkdir -p $tmp_folder_final_musl

########################
# Tracer
########################
tmp_folder_tracer=$tmp_folder/tracer
tmp_folder_tracer_archive_gnu=$tmp_folder_tracer/dd-library-php-x86_64-gnu.tar.gz
tmp_folder_tracer_archive_musl=$tmp_folder_tracer/dd-library-php-x86_64-gnu.tar.gz
mkdir -p $tmp_folder_tracer
curl -L -o $tmp_folder_tracer_archive_gnu $tracer_gnu_url
tar -xf $tmp_folder_tracer_archive_gnu -C $tmp_folder_final_gnu
curl -L -o $tmp_folder_tracer_archive_musl $tracer_musl_url
tar -xf $tmp_folder_tracer_archive_musl -C $tmp_folder_final_musl

########################
# Profiler
########################
tmp_folder_profiler=$tmp_folder/profiler
tmp_folder_profiler_archive=$tmp_folder_profiler/datadog-profiling.tar.gz
mkdir -p $tmp_folder_profiler
curl -L -o $tmp_folder_profiler_archive $profiler_url
tar -xf $tmp_folder_profiler_archive -C $tmp_folder_profiler

# Extension
php_apis=(20160303 20170718 20180731 20190902 20200930)
for version in "${php_apis[@]}"
do
    mkdir -p $tmp_folder_final/x86_64-gnu/dd-library-php/profiler/ext/$version $tmp_folder_final/x86_64-musl/dd-library-php/profiler/ext/$version
    cp $tmp_folder_profiler/datadog-profiling/x86_64-linux-gnu/lib/php/$version/datadog-profiling.so $tmp_folder_final/x86_64-gnu/dd-library-php/profiler/ext/$version/datadog-profiling.so
    cp $tmp_folder_profiler/datadog-profiling/x86_64-linux-musl/lib/php/$version/datadog-profiling.so $tmp_folder_final/x86_64-musl/dd-library-php/profiler/ext/$version/datadog-profiling.so
done

########################
# Final archives
########################
echo "$release_version" > /tmp/dd-library-php/final/x86_64-gnu/dd-library-php/VERSION
tar -czvf dd-library-php-x86_64-gnu.tar.gz -C /tmp/dd-library-php/final/x86_64-gnu .

echo "$release_version" > /tmp/dd-library-php/final/x86_64-musl/dd-library-php/VERSION
tar -czvf dd-library-php-x86_64-musl.tar.gz -C /tmp/dd-library-php/final/x86_64-musl .
