"""Checksum-verified PHP source repositories."""

load(":php_versions.bzl", "PHP_RELEASES")

def _php_source_repository_impl(rctx):
    version = rctx.attr.version
    rctx.download_and_extract(
        url = "https://www.php.net/distributions/php-{}.tar.gz".format(version),
        sha256 = rctx.attr.sha256,
        stripPrefix = "php-{}".format(version),
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
        "version": attr.string(mandatory = True),
    },
)

def _php_sources_impl(mctx):
    direct_deps = []
    for minor, release in PHP_RELEASES.items():
        name = "php_{}".format(minor.replace(".", "_"))
        _php_source_repository(
            name = name,
            sha256 = release.sha256,
            version = release.version,
        )
        direct_deps.append(name)

    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = direct_deps,
        root_module_direct_dev_deps = [],
    )

php_sources = module_extension(implementation = _php_sources_impl)
