#!/bin/sh
# Install the exact Bazel binary used by the CI measurement lane.
set -eu

version=$(tail -n 1 .bazelversion)
if [ "$version" != 9.2.0 ]; then
    echo "unsupported Bazel version in .bazelversion: $version" >&2
    exit 2
fi

case "$(uname -s)/$(uname -m)" in
    Linux/x86_64)
        asset="bazel-${version}-linux-x86_64"
        sha256="7668a95db1250f12c40407251e4e203b4ec8bf39bc495d2f485b2d8c99048694"
        ;;
    Linux/aarch64 | Linux/arm64)
        asset="bazel-${version}-linux-arm64"
        sha256="049dd21f40ad979db11c3ee68c96a42ce75f1185e69ac61ab20de1501427a410"
        ;;
    *)
        echo "unsupported Bazel bootstrap platform: $(uname -s)/$(uname -m)" >&2
        exit 2
        ;;
esac

destination="${PWD}/build/bin/bazel"
temporary="${destination}.tmp"
mkdir -p "$(dirname "$destination")"
trap 'rm -f "$temporary"' EXIT HUP INT TERM
curl --fail --location --show-error --silent \
    "https://github.com/bazelbuild/bazel/releases/download/${version}/${asset}" \
    --output "$temporary"
printf '%s  %s\n' "$sha256" "$temporary" | sha256sum --check --status
chmod 0755 "$temporary"
mv -f "$temporary" "$destination"
trap - EXIT HUP INT TERM
"$destination" --version | grep -Fx "bazel $version"
