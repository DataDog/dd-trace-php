"""Checksum-verified PHP source repositories; fetching occurs only in setup."""

load(":php_versions.bzl", "PHP_SOURCES")

def _php_source_repository_impl(rctx):
    rctx.download_and_extract(
        url = rctx.attr.url,
        sha256 = rctx.attr.sha256,
        stripPrefix = "php-{}".format(rctx.attr.version),
    )
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = ["//visibility:public"])

filegroup(
    name = "srcs",
    srcs = glob(["**"]),
)

exports_files(["configure"])
""",
    )
    return rctx.repo_metadata(reproducible = True)

_php_source_repository = repository_rule(
    implementation = _php_source_repository_impl,
    attrs = {
        "sha256": attr.string(mandatory = True),
        "url": attr.string(mandatory = True),
        "version": attr.string(mandatory = True),
    },
)

def _php_sources_impl(mctx):
    direct_deps = []
    for name in sorted(PHP_SOURCES.keys()):
        release = PHP_SOURCES[name]
        _php_source_repository(
            name = name,
            sha256 = release.sha256,
            url = release.url,
            version = release.version,
        )
        direct_deps.append(name)

    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = direct_deps,
        root_module_direct_dev_deps = [],
    )

php_sources = module_extension(implementation = _php_sources_impl)
