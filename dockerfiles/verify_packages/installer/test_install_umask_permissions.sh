#!/usr/bin/env sh

set -e

. "$(dirname ${0})/utils.sh"

# Initially no ddtrace
assert_no_ddtrace

uname=$(uname -a)
arch=$(if [ -z "${uname##*arm*}" ] || [ -z "${uname##*aarch*}" ]; then echo aarch64; else echo x86_64; fi)
version=$(cat VERSION)

if ! [ -f "build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz" ]; then
    echo "SKIPPED: this test runs only in CI as it requires the .tar.gz at a specific path"
    exit 0
fi

bundle="build/packages/dd-library-php-${version}-${arch}-linux-gnu.tar.gz"

# The symptom the permissions are about: another user must be able to load ddtrace.
# cd / so that su does not fail on a working directory it cannot reach.
useradd -m ddtracetest
assert_ddtrace_loadable_by_other_user() {
    output=$(su ddtracetest -c "cd / && php -m" 2>&1)
    if [ -z "${output##*ddtrace*}" ]; then
        echo "Ok: ddtrace is loadable by another user\n"
    else
        echo "Error: ddtrace is not loadable by another user\n---\n${output}\n---\n"
        exit 1
    fi
}

# Install with a umask that hides everything from "other": the installed files
# must still be readable, as php-fpm workers usually run as another user.
umask 0027

php ./build/packages/datadog-setup.php --php-bin php --file "${bundle}"

extension_dir=$(get_php_extension_dir)
conf_dir=$(get_php_conf_dir)

assert_world_readable_tree "${extension_dir}/ddtrace.so"
assert_world_readable_tree /opt/datadog/dd-library/${version}
assert_world_traversable /opt/datadog
assert_world_traversable /opt/datadog/dd-library
assert_world_readable_tree "${conf_dir}/98-ddtrace.ini"
assert_ddtrace_loadable_by_other_user

# Re-installing must repair a scan directory file left unreadable by an earlier
# run, whatever its name: the installer adopts the name of the file already
# loading ddtrace (see find_main_ini_files). The php.ini sitting next to the scan
# directory - the mainstream Debian/RHEL layout - must not be widened: it is the
# user's and can hold credentials.
custom_ini_file="${conf_dir}/40-ddtrace.ini"
mv "${conf_dir}/98-ddtrace.ini" "${custom_ini_file}"
chmod 0640 "${custom_ini_file}"

main_ini="$(dirname "${conf_dir}")/php.ini"
if ! [ -f "${main_ini}" ]; then
    echo "" > "${main_ini}"
fi
chmod 0640 "${main_ini}"

php ./build/packages/datadog-setup.php --php-bin php --file "${bundle}"

assert_file_not_exists "${conf_dir}/98-ddtrace.ini"
assert_world_readable_tree "${custom_ini_file}"
assert_not_world_readable "${main_ini}"
assert_ddtrace_loadable_by_other_user

# --ini can point at a php.ini outside the scan directory - debian ships one per
# SAPI, and find_main_ini_files() only derives the apache2 sibling, so this is the
# documented way to reach fpm. Such a file is the user's: we write to it, but we
# must not widen it, even though it is not the php.ini this SAPI loaded.
other_sapi_ini="$(dirname "${conf_dir}")/fpm-php.ini"
echo "" > "${other_sapi_ini}"

install_log=/tmp/install-other-sapi.log
install_with_other_sapi_ini() (
    chmod "${1}" "${other_sapi_ini}"
    php ./build/packages/datadog-setup.php --php-bin php --file "${bundle}" \
        --ini "${other_sapi_ini}" > "${install_log}" 2>&1
)

# Unreadable by anyone but the owner: we will not fix the mode, so the user has
# to be told rather than left with a tracer that silently never loads.
install_with_other_sapi_ini 0600
cat "${install_log}"
assert_file_contains "${other_sapi_ini}" 'extension = ddtrace'
assert_not_world_readable "${other_sapi_ini}"
assert_file_contains "${install_log}" 'is not readable by other users'

# Group-readable is the deliberate '0640 root:www-data' hardening, which works:
# advise nothing, and above all do not widen it.
install_with_other_sapi_ini 0640
assert_not_world_readable "${other_sapi_ini}"
assert_file_not_contains "${install_log}" 'is not readable by other users'

# Already readable: nothing to say either.
install_with_other_sapi_ini 0644
assert_file_not_contains "${install_log}" 'is not readable by other users'
