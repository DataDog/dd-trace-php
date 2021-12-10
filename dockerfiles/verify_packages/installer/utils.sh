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

assert_no_ddtrace() {
    output="$(php -v)"
    if [ -z "${output##*ddtrace*}" ]; then
        echo "---\nError: ddtrace should not be installed\n---\n${1}\n---\n"
        exit 1
    fi
    echo "Ok: ddtrace is not installed"
}

assert_no_ddappsec() {
    output="$(php -v)"
    if [ -z "${output##*ddappsec*}" ]; then
        echo "---\nError: ddappsec should not be installed\n---\n${1}\n---\n"
        exit 1
    fi
    echo "Ok: ddappsec is not installed"
}

assert_ddtrace_installed() {
    if php -r 'exit(extension_loaded("ddtrace") ? 0 : 1);'; then
        echo "OK: ddtrace is installed"
    else
        echo "---\nError: ddtrace should be installed\n---\n${1}\n---\n"
        exit 1
    fi
}

assert_ddappsec_installed() {
    if php -r 'exit(extension_loaded("ddappsec") ? 0 : 1);'; then
        echo "OK: ddappsec is installed"
    else
        echo "---\nError: ddappsec should be installed\n---\n${1}\n---\n"
        exit 1
    fi
}

assert_ddtrace_version() {
    output="$(php -v)"
    if [ -z "${output##*ddtrace v${1}*}" ]; then
        echo "---\nOk: ddtrace version '${1}' is correctly installed\n---\n${output}\n---\n"
    else
        echo "---\nError: Wrong version. Expected: ${1}\n---\n${output}\n---\n"
        exit 1
    fi
}

assert_ddappsec_disabled() {
    if php -r 'exit(ini_get("ddappsec.enabled") ? 0 : 1);'; then
        echo "---\nError: ddappsec is enabled. Expected it disableld \n---\n"
        exit 1
    else
        echo "OK: ddappsec is disabled\n"
    fi
}

assert_ddappsec_enabled() {
    if php -r 'exit(ini_get("ddappsec.enabled") ? 0 : 1);'; then
        echo "OK: ddappsec is enabled\n"
    else
        echo "---\nError: ddappsec is disabled. Expected it enabled \n---\n"
        exit 1
    fi
}

assert_file_exists() {
    file="${1}"
    if [ ! -f "${file}" ]; then
        echo "Error: File '${file}' does not exist\n"
        exit 1
    else
        echo "Ok: File '${file}' exists\n"
    fi
}

php_extension_dir() {
    php -r 'echo ini_get("extension_dir");'
}

php_conf_dir() {
    php -i | grep '^Scan this dir' | sed 's/.*=> //'
}

install_legacy_ddtrace() {
    version=$1
    curl -L --output "/tmp/legacy-${version}.tar.gz" \
        "https://github.com/DataDog/dd-trace-php/releases/download/${version}/datadog-php-tracer-${version}.x86_64.tar.gz"
    tar -xf  "/tmp/legacy-${version}.tar.gz" -C /
    /opt/datadog-php/bin/post-install.sh
}
