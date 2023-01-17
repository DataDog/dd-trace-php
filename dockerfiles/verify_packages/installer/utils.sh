#!/usr/bin/env sh

# Common functions used to test the installation process

assert_file_contains() {
    output=$(cat ${1})
    if [ -z "${output##*$2*}" ]; then
        echo "Ok: file $1 contains text '$2'"
    else
        echo "---\nError: file $1 does not contain text '$2'\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_file_not_contains() {
    output=$(cat ${1})
    if [ -z "${output##*$2*}" ]; then
        echo "---\nError: file $1 contains text '$2'\n---\n${output}\n---\n"
        exit 1
    else
        echo "Ok: file $1 does not contains text '$2'"
    fi
}

assert_no_ddtrace() {
    output="$(php -v)"
    if [ -z "${output##*ddtrace*}" ]; then
        echo "---\nError: ddtrace should not be installed\n---\n${1}\n---\n"
        exit 1
    fi
    echo "Ok: ddtrace is not installed"
}

assert_no_profiler() {
    output="$(php -v)"
    if [ -z "${output##*datadog-profiling*}" ]; then
        echo "---\nError: profiler should not be installed\n---\n${1}\n---\n"
        exit 1
    fi
    echo "Ok: profiler is not installed"
}

assert_ddtrace_version() {
    expected_version=${1}
    php_bin=${2:-php}
    output="$($php_bin -v)"
    if [ -z "${output##*ddtrace v${expected_version}*}" ]; then
        echo "---\nOk: ddtrace version '${expected_version}' is correctly installed\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddtrace version. Expected: ${expected_version}\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_appsec_version() {
    output="$(php --ri ddappsec)"
    if [ -z "${output##*Version => ${1}*}" ]; then
        echo "---\nOk: ddappsec version '${1}' is correctly installed\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddappsec version. Expected: ${1}\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_no_appsec() {
    output="$(php -m)"
    if [ -z "${output##*ddappsec*}" ]; then
        echo "---\nError: ddappsec should not be installed\n---\n${1}\n---\n"
        exit 1
    fi
    echo "Ok: ddappsec is not installed"
}

assert_profiler_version() {
    output="$(php -v)"
    if [ -z "${output##*datadog-profiling v${1}*}" ]; then
        echo "---\nOk: datadog-profiling version '${1}' is correctly installed\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong datadog-profiling version. Expected: ${1}\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_appsec_enabled() {
    output="$(php --ri ddappsec)"
    if [ -z "${output##*datadog.appsec.enabled => 1*}" ]; then
        echo "---\nOk: ddappsec is enabled\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddappsec should be enabled\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_appsec_disabled() {
    output="$(php --ri ddappsec)"
    if [ -n "${output##*datadog.appsec.enabled => 1*}" ]; then
        echo "---\nOk: ddappsec is not enabled\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddappsec should not be enabled\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_request_init_hook_exists() {
    php_bin=${1:-php}
    assert_file_exists $($php_bin -r 'echo ini_get("datadog.trace.request_init_hook");')
}

assert_file_exists() {
    file="${1}"
    if [ -f "${file}" ]; then
        echo "Ok: File '${file}' exists\n"
    else
        echo "Error: File '${file}' does not exist\n"
        exit 1
    fi
}

assert_file_not_exists() {
    file="${1}"
    if ! [ -f "${file}" ]; then
        echo "Ok: File '${file}' does not exist\n"
    else
        echo "Error: File '${file}' exists\n"
        exit 1
    fi
}

install_legacy_ddtrace() {
    version=$1
    curl -L --output "/tmp/legacy-${version}.tar.gz" \
        "https://github.com/DataDog/dd-trace-php/releases/download/${version}/datadog-php-tracer-${version}.x86_64.tar.gz"
    tar -xf  "/tmp/legacy-${version}.tar.gz" -C /
    /opt/datadog-php/bin/post-install.sh
}

get_php_conf_dir() {
    php_bin=${1:-php}
    $php_bin -i | grep -i 'scan this dir for additional .ini files' | awk '{print $NF}'
}

get_php_main_conf() {
    php_bin=${1:-php}
    $php_bin -i | grep -i 'Loaded Configuration File' | awk '{print $NF}'
}

get_php_extension_dir() {
    php_bin=${1:-php}
    $php_bin -i | grep -i '^extension_dir' | awk '{print $NF}'
}

generate_installers() {
    version="${1}"
    sh "$(pwd)/tooling/bin/generate-installers.sh" "${version}" "$(pwd)/build/packages"
}

fetch_setup_for_version() {
    version="${1?}"
    sha256sum="${2?}"
    destdir="${3?}"

    mkdir -vp "${destdir?}"
    cd "${destdir}"
    curl -OL https://github.com/DataDog/dd-trace-php/releases/download/${version}/datadog-setup.php
    echo "${sha256sum}  datadog-setup.php" | sha256sum -c
    cd -
}

parse_trace_version() {
    awk -F\' '/const VERSION/ {print $2}' < src/DDTrace/Tracer.php
}

parse_profiling_version() {
    awk -F\" '/^version[ \t]*=/ {print $2}' < profiling/Cargo.toml
}

dashed_print() {
    echo "---"
    for line in "$@" ; do
        printf '%s\n---\n' "$line"
    done
}

is_appsec_installable() {
  uname=$(uname -a)
  [ -n "${uname##*arm*}" ] && [ -n "${uname##*aarch*}" ]
}

