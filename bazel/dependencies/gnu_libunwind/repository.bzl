"""Repository setup for Cargo-locked GNU libunwind sources."""

_CRATE = struct(
    name = "libdd-libunwind-sys",
    version = "1.0.3",
    cargo_checksum = "cf4d5ce7d99aa9244d4dff12d25faf8ad96793183ed049b20f366ce88cf59e68",
    vcs_revision = "e824cac9ee42ea8ae77d8cd5373452ea5cb1954e",
    url = "https://static.crates.io/crates/libdd-libunwind-sys/libdd-libunwind-sys-1.0.3.crate",
    strip_prefix = "libdd-libunwind-sys-1.0.3",
)

def _libdd_libunwind_source_impl(rctx):
    # Cargo's package checksum is the SHA-256 of the .crate (a gzip tarball),
    # so this locks the bytes Cargo.lock resolves, including vendored GNU 1.8.3.
    rctx.download_and_extract(
        url = _CRATE.url,
        sha256 = _CRATE.cargo_checksum,
        stripPrefix = _CRATE.strip_prefix,
        type = "tar.gz",
    )
    vcs = json.decode(rctx.read(".cargo_vcs_info.json"))
    if vcs.get("git", {}).get("sha1") != _CRATE.vcs_revision:
        fail("libdd-libunwind-sys source revision does not match Cargo package lock")
    rctx.file("source.lock.json", json.encode(_CRATE) + "\n")
    rctx.file(
        "BUILD.bazel",
        """package(default_visibility = [\"//visibility:public\"])

exports_files([\"libunwind/configure\", \"source.lock.json\"])

filegroup(
    name = \"srcs\",
    srcs = glob([\"libunwind/**\"], exclude = [\"BUILD\", \"BUILD.bazel\"]),
)

filegroup(
    name = \"headers\",
    srcs = glob([\"libunwind/include/**\"]),
)

alias(name = \"configure\", actual = \"libunwind/configure\")
""",
    )
    return rctx.repo_metadata(reproducible = True)

libdd_libunwind_source = repository_rule(
    implementation = _libdd_libunwind_source_impl,
)

def _gnu_libunwind_sources_impl(mctx):
    libdd_libunwind_source(name = "libdd_libunwind_sys_1_0_3_source")
    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = ["libdd_libunwind_sys_1_0_3_source"],
        root_module_direct_dev_deps = [],
    )

gnu_libunwind_sources = module_extension(implementation = _gnu_libunwind_sources_impl)
