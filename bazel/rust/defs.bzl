"""Native Rust targets for dd-trace-php workspace members.

Load and invoke `ddtrace_rust_targets()` from the repository-root BUILD file.
Keeping the targets in that package lets their source globs describe the
existing Cargo layout without adding BUILD files throughout the checkout.
"""

load("@ddtrace_rust_crates//:defs.bzl", generator_aliases = "aliases", generator_all_crate_deps = "all_crate_deps")
load("@rules_rs//rs:rust_binary.bzl", "rust_binary")
load("@rules_rs//rs:rust_library.bzl", public_rust_library = "rust_library")
load("//bazel/rust:dependency_guard.bzl", "rust_no_aws_lc_sys")
load("//bazel/rust:ffi_headers.bzl", "ffi_header_targets")
load(
    "//bazel/rust:libdatadog.bzl",
    "libdatadog_native_aliases",
    "libdatadog_native_deps",
    "libdatadog_native_proc_macro_deps",
    "libdatadog_rust_targets",
)
load(
    "//bazel/rust:libdatadog_profiler.bzl",
    "libdatadog_profiler_native_aliases",
    "libdatadog_profiler_native_deps",
    "libdatadog_profiler_native_proc_macro_deps",
    "libdatadog_profiler_rust_targets",
)
load("//bazel/rust:libddwaf.bzl", "libddwaf_rust_targets")
load("//bazel/rust:product_feature_guard.bzl", "assert_product_feature_resolution")
load("//bazel/rust:product_profile.bzl", "rust_product_archive")
load("//bazel/rust:profiler_matrix.bzl", "profiler_matrix_targets")
load("//bazel/rust:root_features.bzl", "ROOT_FEATURE_CHECK")
load(":exact_rules.bzl", "rust_library", "rust_static_library")

_COMMON_RUSTC_FLAGS = [
    "--cfg=tokio_unstable",
    "--check-cfg=cfg(test)",
    "--check-cfg=cfg(tokio_unstable)",
]

def ddtrace_rust_targets():
    """Declares real Rust outputs for every root Cargo workspace member."""

    assert_product_feature_resolution()
    ffi_header_targets()
    libddwaf_rust_targets()

    # crate_universe deliberately leaves Cargo path dependencies as native
    # Bazel labels. Declare the resolved pinned libdatadog closure first.
    libdatadog_rust_targets()
    libdatadog_profiler_rust_targets()
    profiler_matrix_targets()

    # Native fat-product startup validation uses the production sidecar IPC
    # protocol. The client stays in the product's target configuration so it
    # exercises the same exact Cargo unit graph and target ABI as the DSO.
    rust_binary(
        name = "rust_sidecar_ping_client",
        srcs = ["//bazel/products/tracer/fat:sidecar-ping.rs"],
        crate_name = "ddtrace_sidecar_ping_client",
        deps = [
            ":rust_libdatadog_datadog_sidecar",
            ":rust_libdatadog_libdd_ipc",
        ],
        edition = "2021",
        rustc_flags = _COMMON_RUSTC_FLAGS,
    )

    rust_library(
        name = "rust_appsec_helper",
        srcs = native.glob(["appsec/helper-rust/src/**/*.rs"]),
        aliases = libdatadog_native_aliases("appsec/helper-rust"),
        compile_data = [
            "appsec/helper-rust/Cargo.toml",
            "appsec/recommended.json",
        ],
        crate_name = "ddappsec_helper",
        crate_root = "appsec/helper-rust/src/lib.rs",
        deps = libdatadog_native_deps("appsec/helper-rust"),
        edition = "2021",
        proc_macro_deps = libdatadog_native_proc_macro_deps("appsec/helper-rust"),
        rustc_env_files = ["@ddtrace_rust_workspace//:helper_version.env"],
        rustc_flags = _COMMON_RUSTC_FLAGS + ["--check-cfg=cfg(feature,values(\"coverage\"))"],
    )

    rust_library(
        name = "rust_ddtrace_sidecar",
        srcs = native.glob(["sidecar/src/**/*.rs"]),
        aliases = libdatadog_native_aliases("sidecar"),
        compile_data = ["sidecar/Cargo.toml"],
        crate_name = "ddtrace_sidecar",
        crate_root = "sidecar/src/lib.rs",
        deps = libdatadog_native_deps("sidecar"),
        edition = "2021",
        proc_macro_deps = libdatadog_native_proc_macro_deps("sidecar"),
        rustc_flags = _COMMON_RUSTC_FLAGS + ["--check-cfg=cfg(feature,values(\"helper-rust-coverage\"))"],
    )

    rust_static_library(
        name = "_rust_datadog_php_profile_input",
        srcs = native.glob([
            "components-rs/**/*.rs",
            "tracer/**/*.rs",
        ], exclude = ["components-rs/php_sidecar_mockgen/**/*.rs"]),
        aliases = libdatadog_native_aliases(""),
        compile_data = [
            "Cargo.toml",
            "VERSION",
        ],
        crate_features = ["tracer"],
        crate_name = "datadog_php",
        crate_root = "components-rs/lib.rs",
        deps = libdatadog_native_deps(""),
        edition = "2021",
        proc_macro_deps = libdatadog_native_proc_macro_deps(""),
        rustc_flags = _COMMON_RUSTC_FLAGS + [
            ROOT_FEATURE_CHECK,
            "--cfg=php_shared_build",
            "--check-cfg=cfg(php_shared_build)",
            "--check-cfg=cfg(standalone_profiler)",
        ],
    )

    rust_product_archive(
        name = "rust_datadog_php",
        deps = ":_rust_datadog_php_profile_input",
        product = "tracer",
    )

    rust_static_library(
        name = "_rust_datadog_php_profiler_profile_input",
        srcs = native.glob([
            "components-rs/**/*.rs",
            "profiling/**/*.rs",
        ], exclude = ["components-rs/php_sidecar_mockgen/**/*.rs"]),
        aliases = libdatadog_profiler_native_aliases(""),
        compile_data = [
            "Cargo.toml",
            "VERSION",
        ],
        crate_features = [
            "io_profiling",
            "profiling",
        ],
        crate_name = "datadog_php_profiler",
        crate_root = "components-rs/lib.rs",
        deps = libdatadog_profiler_native_deps(""),
        edition = "2021",
        proc_macro_deps = libdatadog_profiler_native_proc_macro_deps(""),
        rustc_flags = _COMMON_RUSTC_FLAGS + [
            ROOT_FEATURE_CHECK,
            "--cfg=php_shared_build",
            "--cfg=standalone_profiler",
            "--check-cfg=cfg(php_shared_build)",
            "--check-cfg=cfg(standalone_profiler)",
        ],
    )

    rust_product_archive(
        name = "rust_datadog_php_profiler",
        deps = ":_rust_datadog_php_profiler_profile_input",
        product = "profiler",
    )

    # components-rs/build.rs used `cargo tree -i aws-lc-sys` to enforce this
    # invariant. The aspect checks the configured native Bazel dependency
    # graph and permits unreachable Cargo.lock entries.
    rust_no_aws_lc_sys(
        name = "rust_no_aws_lc_sys",
        target = ":rust_datadog_php",
    )
    rust_no_aws_lc_sys(
        name = "rust_profiler_no_aws_lc_sys",
        target = ":rust_datadog_php_profiler",
    )

    # Consumers use this target through a cfg="exec" tool attribute. That
    # keeps mock generation on stable, uninstrumented execution toolchains
    # even when the requested PHP artifact uses the nightly ASan toolchain.
    public_rust_library(
        name = "rust_libdatadog_tools_sidecar_mockgen",
        srcs = ["@libdatadog_source//:rust_libdatadog_tools_sidecar_mockgen_srcs"],
        aliases = generator_aliases(package_name = "libdatadog/tools/sidecar_mockgen"),
        compile_data = ["@libdatadog_source//:rust_libdatadog_tools_sidecar_mockgen_data"],
        crate_name = "sidecar_mockgen",
        crate_root = "@libdatadog_source//:tools/sidecar_mockgen/src/lib.rs",
        deps = generator_all_crate_deps(
            cargo_only = True,
            normal = True,
            package_name = "libdatadog/tools/sidecar_mockgen",
        ),
        edition = "2021",
        rustc_flags = _COMMON_RUSTC_FLAGS,
    )

    rust_binary(
        name = "php_sidecar_mockgen",
        srcs = ["components-rs/php_sidecar_mockgen/src/bin/php_sidecar_mockgen.rs"],
        aliases = {":rust_libdatadog_tools_sidecar_mockgen": "sidecar_mockgen"},
        compile_data = ["components-rs/php_sidecar_mockgen/Cargo.toml"],
        crate_name = "php_sidecar_mockgen",
        crate_root = "components-rs/php_sidecar_mockgen/src/bin/php_sidecar_mockgen.rs",
        deps = generator_all_crate_deps(
            cargo_only = True,
            normal = True,
            package_name = "components-rs/php_sidecar_mockgen",
        ) + [":rust_libdatadog_tools_sidecar_mockgen"],
        edition = "2021",
        rustc_flags = _COMMON_RUSTC_FLAGS,
    )

    native.filegroup(
        name = "rust_native_outputs",
        srcs = [
            ":php_sidecar_mockgen",
            ":rust_appsec_helper",
            ":rust_datadog_php",
            ":rust_datadog_php_profiler",
            ":rust_ddtrace_sidecar",
            ":rust_no_aws_lc_sys",
            ":rust_ffi_header_checks",
            ":rust_profiler_no_aws_lc_sys",
        ],
    )
