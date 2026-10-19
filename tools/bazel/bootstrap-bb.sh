#!/bin/sh
set -eu

version=5.0.468
os=$(uname -s | tr '[:upper:]' '[:lower:]')
machine=$(uname -m)

case "${os}/${machine}" in
    linux/x86_64)
        asset="bazel-${version}-linux-x86_64"
        sha256="60ff9efcb285dd951ed2f675e7cc8c2608be09a00d3bcff35bea2e783eef8049"
        ;;
    linux/aarch64 | linux/arm64)
        asset="bazel-${version}-linux-arm64"
        sha256="4677e924049bb5507dfaf1b7af36343dc72eb46c1cff62863dcd57c01c963e4e"
        ;;
    *)
        echo "unsupported bootstrap platform: ${os}/${machine}" >&2
        exit 2
        ;;
esac

destination="${PWD}/build/bin/bb"
temporary="${destination}.tmp"
url="https://github.com/buildbuddy-io/bazel/releases/download/${version}/${asset}"

mkdir -p "$(dirname "${destination}")"
trap 'rm -f "${temporary}"' EXIT HUP INT TERM
curl --fail --location --show-error --silent "${url}" --output "${temporary}"
printf '%s  %s\n' "${sha256}" "${temporary}" | sha256sum --check --status
chmod 0755 "${temporary}"
mv "${temporary}" "${destination}"
ln -sf bb "$(dirname "${destination}")/bazel"
trap - EXIT HUP INT TERM

echo "installed BuildBuddy CLI ${version} at ${destination}"
