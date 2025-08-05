#!/usr/bin/env sh

set -e

release_version=$1
packages_build_dir=$2

########################
# Installers
########################
if echo "${release_version}" | grep -qE '\+' && [ -n ${CI_JOB_ID:-} ]; then
  replacement="define('RELEASE_VERSION', urlencode('${release_version}'))"
  sed -r "s|const RELEASE_VERSION[^;]+|${replacement}|g" ./datadog-setup.php > "${packages_build_dir}/datadog-setup.php"

  replacement="define('RELEASE_URL_PREFIX', 'https://s3.us-east-1.amazonaws.com/dd-trace-php-builds/' . RELEASE_VERSION . '/')"
  sed -ri "s|define\('RELEASE_URL_PREFIX'[^;]+|${replacement}|" "${packages_build_dir}/datadog-setup.php"
else
  sed "s|@release_version@|${release_version}|g" ./datadog-setup.php > "${packages_build_dir}/datadog-setup.php"
fi
