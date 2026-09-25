"""Checksum-locked repository setup for the Datadog Rust gitlinks."""

load("//bazel/rust:workspace_crates.bzl", "LIBDATADOG_CRATES", "rust_workspace_target_name")
load(":manifest.bzl", "GITLINK_INPUTS")

_BUILD = """package(default_visibility = ["//visibility:public"])
filegroup(name = "srcs", srcs = glob(["**"], exclude = ["BUILD", "BUILD.bazel"]))
filegroup(name = "cargo_lock", srcs = glob(["**/Cargo.lock"], allow_empty = True))
filegroup(name = "cargo_manifests", srcs = glob(["**/Cargo.toml"]))
"""

def _repository_build(source_name):
    build = _BUILD
    if source_name == "libddwaf_rust":
        return build + """
filegroup(name = "rust_libddwaf_srcs", srcs = glob(["crates/libddwaf/src/**/*.rs"]))
filegroup(name = "rust_libddwaf_data", srcs = glob(["crates/libddwaf/**"], exclude = ["crates/libddwaf/src/**/*.rs"]))
filegroup(name = "rust_libddwaf_sys_srcs", srcs = glob(["crates/libddwaf-sys/src/**/*.rs"]))
filegroup(name = "rust_libddwaf_sys_data", srcs = glob(["crates/libddwaf-sys/**"], exclude = ["crates/libddwaf-sys/src/**/*.rs"]))
"""
    if source_name != "libdatadog":
        return build
    for crate in LIBDATADOG_CRATES:
        name = rust_workspace_target_name(crate.package)
        build += """
filegroup(
    name = "%s_srcs",
    srcs = glob(["%s/**/*.rs"]),
)
filegroup(
    name = "%s_data",
    srcs = glob(["%s/**"], exclude = ["%s/**/*.rs"]),
)
""" % (name, crate.source_dir, name, crate.source_dir, crate.source_dir)
    return build

def _source_impl(rctx):
    source = GITLINK_INPUTS[rctx.attr.source_name]
    rctx.download_and_extract(
        url = source.url,
        output = "",
        sha256 = source.sha256,
        stripPrefix = source.strip_prefix,
        type = "tar.gz",
    )
    for nested in source.nested:
        rctx.download_and_extract(
            url = nested.url,
            output = nested.path,
            sha256 = nested.sha256,
            stripPrefix = nested.strip_prefix,
            type = "tar.gz",
        )
    for patch in rctx.attr.patches:
        rctx.patch(patch, strip = 1)
    rctx.file("BUILD.bazel", _repository_build(rctx.attr.source_name))
    return rctx.repo_metadata(reproducible = True)

_source_repository = repository_rule(
    implementation = _source_impl,
    attrs = {
        "patches": attr.label_list(),
        "source_name": attr.string(mandatory = True),
    },
)

def _gitlink_sources_impl(mctx):
    _source_repository(
        name = "libdatadog_source",
        patches = ["//bazel/rust:spawn-worker-explicit-artifacts.patch"],
        source_name = "libdatadog",
    )
    _source_repository(
        name = "libddwaf_rust_source",
        patches = [
            "//bazel/rust:libddwaf-source-explicit-package-metadata.patch",
            "//bazel/rust:libddwaf-sys-source-explicit-bindings.patch",
            "//bazel/rust:libddwaf-sys-source-explicit-package-metadata.patch",
        ],
        source_name = "libddwaf_rust",
    )
    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = ["libdatadog_source", "libddwaf_rust_source"],
        root_module_direct_dev_deps = [],
    )

gitlink_sources = module_extension(implementation = _gitlink_sources_impl)
