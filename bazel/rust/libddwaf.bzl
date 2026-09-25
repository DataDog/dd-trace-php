"""Native Rust wrappers around the declared libddwaf C++ dependency."""

load(":exact_rules.bzl", "rust_library")
load(
    ":libdatadog.bzl",
    "libdatadog_native_aliases",
    "libdatadog_native_deps",
    "libdatadog_native_features",
    "libdatadog_native_proc_macro_deps",
)
load(":llvm20_bindgen.bzl", "llvm20_rust_bindgen")

_LIBDDWAF = "appsec/third_party/libddwaf-rust/crates/libddwaf"
_LIBDDWAF_SYS = "appsec/third_party/libddwaf-rust/crates/libddwaf-sys"

def libddwaf_rust_targets(
        native_library = "//bazel/products/libddwaf:libddwaf",
        public_header = "//bazel/products/libddwaf:ddwaf_h"):
    """Declares bindgen, sys, and safe Rust targets for pinned libddwaf."""
    llvm20_rust_bindgen(
        name = "rust_libddwaf_bindings",
        bindgen_flags = [
            "--allowlist-function=^ddwaf_.*",
            "--default-visibility=public",
            "--no-prepend-enum-name",
            "--with-derive-default",
        ],
        cc_lib = native_library,
        header = public_header,
    )

    rust_library(
        name = "rust_native_libddwaf_sys",
        srcs = ["@libddwaf_rust_source//:rust_libddwaf_sys_srcs"],
        aliases = libdatadog_native_aliases(_LIBDDWAF_SYS),
        compile_data = [
            ":rust_libddwaf_bindings",
            "@libddwaf_rust_source//:rust_libddwaf_sys_data",
        ],
        crate_features = libdatadog_native_features(_LIBDDWAF_SYS),
        crate_name = "libddwaf_sys",
        crate_root = "@libddwaf_rust_source//:crates/libddwaf-sys/src/lib.rs",
        deps = libdatadog_native_deps(_LIBDDWAF_SYS) + [native_library],
        edition = "2021",
        proc_macro_deps = libdatadog_native_proc_macro_deps(_LIBDDWAF_SYS),
        rustc_env = {"LIBDDWAF_BINDINGS": "$(execpath :rust_libddwaf_bindings)"},
        version = "2.0.1",
    )

    rust_library(
        name = "rust_native_libddwaf",
        srcs = ["@libddwaf_rust_source//:rust_libddwaf_srcs"],
        aliases = libdatadog_native_aliases(_LIBDDWAF),
        compile_data = ["@libddwaf_rust_source//:rust_libddwaf_data"],
        crate_features = libdatadog_native_features(_LIBDDWAF),
        crate_name = "libddwaf",
        crate_root = "@libddwaf_rust_source//:crates/libddwaf/src/lib.rs",
        deps = libdatadog_native_deps(_LIBDDWAF),
        edition = "2021",
        proc_macro_deps = libdatadog_native_proc_macro_deps(_LIBDDWAF),
        version = "2.0.1",
    )
