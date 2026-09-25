#!/usr/bin/env bash

set -xeuo pipefail
IFS=$'\n\t'

release_version=$1
packages_build_dir=$2
source_dir=$3

mkdir -p "$packages_build_dir"

tmp_folder=${CI_PROJECT_DIR:-.}/tmp/bundle
tmp_folder_final=$tmp_folder/final

architectures=(x86_64 aarch64)
targets=(linux windows)

tracing_php_apis=(
    20151012
    20160303
    20170718
    20180731
    20190902
    20200930
    20210902
    20220829
    20230831
    20240924
    20250925
)
profiler_php_apis=(
    20160303
    20170718
    20180731
    20190902
    20200930
    20210902
    20220829
    20230831
    20240924
    20250925
)
appsec_php_apis=("${tracing_php_apis[@]}")

if [[ -n ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
    tracing_php_apis=(
        20190902
        20200930
        20210902
        20220829
        20230831
        20240924
        20250925
    )
    targets=(linux-gnu)
fi

# A release bundle is portable across glibc and musl. BUNDLE_ARCH therefore
# selects only the architecture; there is deliberately no libc dimension.
if [[ -n ${BUNDLE_ARCH:-} ]]; then
    architectures=("$BUNDLE_ARCH")
    targets=(linux)
elif [[ -n ${TRIPLET:-} ]]; then
    architectures=()
    targets=()

    if [[ $TRIPLET = "x86_64-pc-windows-msvc" ]]; then
        architectures+=(x86_64)
        targets+=(windows)
    else
        IFS='-' read -r architecture triplet_target <<< "$TRIPLET"
        architectures+=("$architecture")
        if [[ -n ${DDTRACE_MAKE_PACKAGES_ASAN:-} ]]; then
            targets+=("${triplet_target#*-}")
        else
            targets+=(linux)
        fi
    fi
fi

copy_with_dir() {
    mkdir -p "$(dirname "$2")"
    cp "$1" "$2"
}

copy_tracing_extension() {
    local architecture=$1
    local php_api=$2
    local config=$3
    local extension=$4
    local output=$5

    copy_with_dir \
        "./extensions_${architecture}/${extension}-${php_api}${config}.${output##*.}" \
        "$output"
}

write_stubs() {
    local stubs=(
        "$source_dir/src/ddtrace_php_api.stubs.php"
        "$source_dir/tracer/ddtrace.stub.php"
        "$source_dir/tracer/hook/uhook.stub.php"
        "$source_dir/tracer/hook/uhook_attributes.stub.php"
    )
    local merged_stubs=""
    local stub content

    for stub in "${stubs[@]}"; do
        content=$(<"$stub")
        content="${content#<?php}"
        merged_stubs+="$content"
    done

    printf '<?php\n%s' "$merged_stubs" \
        > "$packages_build_dir/datadog-tracer.stubs.php"
}

archive_bundle() {
    local root=$1
    local output=$2

    printf '%s\n' "$release_version" > "$root/dd-library-php/VERSION"
    tar --owner=0 --group=0 -czv -f "$packages_build_dir/$output" \
        -C "$root" .
}

build_linux_bundle() {
    local architecture=$1
    local root="$tmp_folder_final/${architecture}-linux"
    local trace_root="$root/dd-library-php/trace"
    local profiling_root="$root/dd-library-php/profiling"
    local appsec_root="$root/dd-library-php/appsec"
    local php_api config

    mkdir -p "$trace_root"
    for php_api in "${tracing_php_apis[@]}"; do
        for config in "" -zts -debug; do
            copy_tracing_extension "$architecture" "$php_api" "$config" \
                ddtrace "$trace_root/ext/${php_api}/ddtrace${config}.so"
        done
    done
    cp -r ./src "$trace_root"

    for php_api in "${profiler_php_apis[@]}"; do
        for config in "" -zts; do
            copy_with_dir \
                "./datadog-profiling/${architecture}/lib/php/${php_api}/datadog-profiling${config}.so" \
                "$profiling_root/ext/${php_api}/datadog-profiling${config}.so"
        done
    done
    cp ./profiling/LICENSE* ./profiling/NOTICE "$profiling_root/"

    for php_api in "${appsec_php_apis[@]}"; do
        for config in "" -zts; do
            copy_with_dir \
                "./appsec_${architecture}/ddappsec-${php_api}${config}.so" \
                "$appsec_root/ext/${php_api}/ddappsec${config}.so"
        done
    done
    copy_with_dir ./appsec/recommended.json "$appsec_root/etc/recommended.json"

    archive_bundle "$root" \
        "dd-library-php-${release_version}-${architecture}-linux.tar.gz"
}

build_asan_bundle() {
    local architecture=$1
    local root="$tmp_folder_final/${architecture}-linux-gnu"
    local trace_root="$root/dd-library-php/trace"
    local php_api

    mkdir -p "$trace_root"
    for php_api in "${tracing_php_apis[@]}"; do
        copy_tracing_extension "$architecture" "$php_api" -debug-zts \
            ddtrace "$trace_root/ext/${php_api}/ddtrace-debug-zts.so"
    done
    cp -r ./src "$trace_root"

    archive_bundle "$root" \
        "dd-library-php-${release_version}-${architecture}-linux-gnu.tar.gz"
}

build_windows_bundle() {
    local architecture=$1
    local root="$tmp_folder_final/${architecture}-windows"
    local trace_root="$root/dd-library-php/trace"
    local php_api config

    if [[ $architecture != x86_64 ]]; then
        return 0
    fi

    mkdir -p "$trace_root"
    for php_api in "${tracing_php_apis[@]}"; do
        # Windows support starts with PHP 7.2.
        if ((php_api < 20170718)); then
            continue
        fi
        for config in "" -zts; do
            copy_tracing_extension "$architecture" "$php_api" "$config" \
                php_ddtrace "$trace_root/ext/${php_api}/php_ddtrace${config}.dll"
        done
    done
    cp -r ./src "$trace_root"

    archive_bundle "$root" \
        "dd-library-php-${release_version}-${architecture}-windows.tar.gz"
}

echo "Architectures: ${architectures[*]}"
echo "Targets: ${targets[*]}"
echo "PHP APIs: ${tracing_php_apis[*]}"

write_stubs

for architecture in "${architectures[@]}"; do
    for target in "${targets[@]}"; do
        rm -rf "$tmp_folder"
        case $target in
            linux)
                build_linux_bundle "$architecture"
                ;;
            linux-gnu)
                build_asan_bundle "$architecture"
                ;;
            windows)
                build_windows_bundle "$architecture"
                ;;
            *)
                echo "Unsupported bundle target: $target" >&2
                exit 1
                ;;
        esac
    done
done
