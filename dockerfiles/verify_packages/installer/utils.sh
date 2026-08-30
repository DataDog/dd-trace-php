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
    if php -r 'exit(filter_var(ini_get("datadog.profiling.enabled"), FILTER_VALIDATE_BOOL) ? 0 : 1);'; then
        echo "---\nError: profiler should not be enabled\n---\n${1}\n---\n"
        exit 1
    fi
    echo "Ok: profiler is not enabled"
}

assert_ddtrace_version() (
    expected_version=${1}
    php_bin=${2:-php}
    output="$($php_bin -v)"
    if [ -z "${output##*ddtrace v${expected_version}*}" ]; then
        echo "---\nOk: ddtrace version '${expected_version}' is correctly installed\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddtrace version. Expected: ${expected_version}\n---\n${output}\n---\n"
        exit 1
    fi
)

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
    assert_ddtrace_version "${1}"
    assert_profiler_installed
}

assert_tracer_installed() {
    php_bin=${1:-php}
    output="$($php_bin -v)"
    if [ -z "${output##*with ddtrace*}" ]; then
        echo "---\nOk: Tracer is installed\n---\n${output}\n---\n"
    else
        echo "---\nError: Tracer should be installed\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_profiler_installed() {
    php_bin=${1:-php}
    if "$php_bin" -r '$value = ini_get("datadog.profiling.enabled"); exit($value !== false && filter_var($value, FILTER_VALIDATE_BOOL) ? 0 : 1);'; then
        echo "Ok: Profiling is available and enabled in ddtrace"
    else
        echo "Error: Profiling should be available and enabled in ddtrace"
        exit 1
    fi
}

assert_appsec_installed() {
    php_bin=${1:-php}
    output="$($php_bin -m)"
    if [ -z "${output##*ddappsec*}" ]; then
        echo "---\nOk: AppSec is installed\n---\n${output}\n---\n"
    else
        echo "---\nError: AppSec should be installed\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_appsec_enabled() {
    output="$(php --ri ddappsec)"
    if [ -z "${output##*Current state => Enabled*}" ]; then
        echo "---\nOk: ddappsec is enabled\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddappsec should be enabled\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_appsec_disabled() {
    output="$(php --ri ddappsec)"
    if [ -n "${output##*Current state => Enabled*}" ]; then
        echo "---\nOk: ddappsec is not enabled\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong ddappsec should not be enabled\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_sources_path_exists() {
    php_bin=${1:-php}
    assert_file_exists $($php_bin -r 'echo ini_get("datadog.trace.sources_path") . "/bridge/_files_api.php";')
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

assert_world_traversable() {
    dir="${1}"
    if ! [ -d "${dir}" ]; then
        echo "Error: Directory '${dir}' does not exist\n"
        exit 1
    fi
    if [ -n "$(find "${dir}" -maxdepth 0 ! -perm -001)" ]; then
        echo "Error: Directory '${dir}' is not traversable by other users\n"
        exit 1
    fi
    echo "Ok: Directory '${dir}' is traversable by other users\n"
}

assert_not_world_readable() {
    file="${1}"
    if ! [ -f "${file}" ]; then
        echo "Error: '${file}' does not exist\n"
        exit 1
    fi
    if [ -n "$(find "${file}" -maxdepth 0 -perm -004)" ]; then
        echo "Error: '${file}' was widened to be readable by other users\n"
        exit 1
    fi
    echo "Ok: '${file}' was left unreadable by other users\n"
}

# The installer must not let its umask leak into what it installs, as the PHP
# process reading those files usually runs as another user.
assert_world_readable_tree() {
    path="${1}"
    if ! [ -e "${path}" ]; then
        echo "Error: '${path}' does not exist\n"
        exit 1
    fi
    unreadable=$(find "${path}" \( -type f ! -perm -004 \) -o \( -type d ! -perm -005 \))
    if [ -n "${unreadable}" ]; then
        echo "Error: not readable by other users under '${path}':\n${unreadable}\n"
        exit 1
    fi
    echo "Ok: Everything under '${path}' is readable by other users\n"
}

install_legacy_ddtrace() (
    version=$1
    curl -L --output "/tmp/legacy-${version}.tar.gz" \
        "https://github.com/DataDog/dd-trace-php/releases/download/${version}/datadog-php-tracer-${version}.x86_64.tar.gz"
    tar -xf  "/tmp/legacy-${version}.tar.gz" -C /
    /opt/datadog-php/bin/post-install.sh
)

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

generate_installers() (
    version="${1}"
    sh "$(pwd)/tooling/bin/generate-installers.sh" "${version}" "/tmp/"
)

fetch_setup_for_version() (
    version="${1?}"
    destdir="${2?}"

    mkdir -vp "${destdir?}"
    cd "${destdir}"
    curl -OL https://github.com/DataDog/dd-trace-php/releases/download/${version}/datadog-setup.php
    cd -
)

parse_appsec_version() {
    grep -oP 'VERSION \K\d+\.\d+\.\d+' appsec/CMakeLists.txt
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

