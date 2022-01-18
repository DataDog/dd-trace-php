#!/usr/bin/env sh

set -e

release_version=$1
packages_build_dir=$2

########################
# Installers
########################
sed "s|@release_version@|${release_version}|g" ./datadog-setup.php > "${packages_build_dir}/datadog-setup-x86_64-linux-gnu.php"
sed "s|@release_version@|${release_version}|g" ./datadog-setup.php > "${packages_build_dir}/datadog-setup-x86_64-linux-musl.php"
