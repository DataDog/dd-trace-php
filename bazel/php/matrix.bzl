"""Deterministic build-matrix manifest generation."""

load(":php_versions.bzl", "ASAN_PHP_VERSIONS", "NORMAL_PHP_VERSIONS", "PHP_RELEASES", "PROFILER_PHP_VERSIONS")

_NORMAL_PLATFORMS = (
    "linux-amd64-glibc",
    "linux-arm64-glibc",
    "linux-amd64-musl",
    "linux-arm64-musl",
)

_ASAN_PLATFORMS = (
    "asan-linux-amd64-glibc",
    "asan-linux-arm64-glibc",
)

def _json_string(value):
    return "\"%s\"" % value

def _json_array(values):
    return "[%s]" % ",".join([_json_string(value) for value in values])

def _matrix_manifest_impl(ctx):
    releases = []
    for minor in NORMAL_PHP_VERSIONS:
        release = PHP_RELEASES[minor]
        releases.append(
            "\"%s\":{\"api\":%d,\"sha256\":\"%s\",\"version\":\"%s\"}" % (
                minor,
                release.api,
                release.sha256,
                release.version,
            ),
        )

    content = "{" + \
              "\"asan_abi_variants\":[\"zts-debug-asan\"]," + \
              "\"asan_php_versions\":" + _json_array(ASAN_PHP_VERSIONS) + "," + \
              "\"asan_platforms\":" + _json_array(_ASAN_PLATFORMS) + "," + \
              "\"normal_php_versions\":" + _json_array(NORMAL_PHP_VERSIONS) + "," + \
              "\"normal_platforms\":" + _json_array(_NORMAL_PLATFORMS) + "," + \
              "\"normal_abi_variants_by_libc\":{" + \
              "\"glibc\":[\"nts\",\"nts-debug\",\"zts\"]," + \
              "\"musl\":[\"nts\",\"zts\"]}," + \
              "\"php_releases\":{" + ",".join(releases) + "}," + \
              "\"profiler_php_versions\":" + _json_array(PROFILER_PHP_VERSIONS) + \
              "}\n"
    ctx.actions.write(ctx.outputs.out, content)
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

matrix_manifest = rule(
    implementation = _matrix_manifest_impl,
    outputs = {"out": "%{name}.json"},
)
