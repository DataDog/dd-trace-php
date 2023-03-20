#!/usr/bin/env sh

set -e

release_version=$1
packages_build_dir=$2

########################
# Installers
########################
sed "s|@release_version@|${release_version}|g" ./datadog-setup.php > "${packages_build_dir}/datadog-setup.php"
if [[ ${release_version} == "1.0.0-nightly" && -n ${CIRCLE_WORKFLOW_JOB_ID:-} ]]; then
  sed -ri "s|define\('RELEASE_URL_PREFIX'[^;]+|const RELEASE_URL_PREFIX = 'https://output.circle-artifacts.com/output/job/${CIRCLE_WORKFLOW_JOB_ID}/artifacts/0/'|" "${packages_build_dir}/datadog-setup.php"
fi
