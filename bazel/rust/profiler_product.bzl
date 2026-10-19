"""One PHP-SDK-specific native profiler product."""

load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/platforms:transitions.bzl", "single_platform_transition")
load(":exact_rules.bzl", "rust_static_library")
load(
    ":libdatadog_profiler.bzl",
    "libdatadog_profiler_native_aliases",
    "libdatadog_profiler_native_deps",
    "libdatadog_profiler_native_proc_macro_deps",
)
load(":php_abi.bzl", "PhpRustAbiInfo")
load(":product_profile.bzl", "RustProductProfileInfo", "rust_product_archive")
load(":profiler_native.bzl", "ProfilerBindingsInfo", "profiler_native_inputs")
load(":root_features.bzl", "ROOT_FEATURE_CHECK")

_PROFILER_FEATURES = [
    "io_profiling",
    "profiling",
]

RustPhpProfilerInfo = provider(
    doc = "Configured PHP ABI and release profile attached to a native profiler archive.",
    fields = {
        "abi_config_header": "Generated C header containing the selected CFG_* values.",
        "abi_manifest": "Generated machine-readable PHP ABI manifest.",
        "archive": "The concrete profiler PIC static archive, including its configured native FFI objects.",
        "bindings": "Declared transformed bindings file byte-identical to the binding copied into the configured Rust OUT_DIR.",
        "configuration_name": "Selected PHP matrix configuration name.",
        "ffi_headers": "Declared profiler and PHP SDK headers consumed by native C compilation.",
        "php_abi": "PhpRustAbiInfo used for Rust bindings and native C sources.",
        "profile": "RustProductProfileInfo for profiler-release.",
        "raw_bindings": "Raw LLVM 20 bindgen output before profiling/build.rs IntKind transformation.",
    },
)

def _configured_profiler_archive_impl(ctx):
    archive = ctx.attr.archive
    abi = ctx.attr.abi[PhpRustAbiInfo]
    profile = archive[RustProductProfileInfo]
    archive_files = archive[DefaultInfo].files.to_list()
    if len(archive_files) != 1 or not archive_files[0].basename.endswith(".a"):
        fail("configured profiler must produce exactly one static archive")
    bindings = ctx.attr.bindings[ProfilerBindingsInfo]
    providers = [
        archive[DefaultInfo],
        archive[CcInfo],
        profile,
        RustPhpProfilerInfo(
            abi_config_header = abi.config_header,
            abi_manifest = abi.manifest,
            archive = archive_files[0],
            bindings = bindings.materialized,
            configuration_name = abi.php.configuration_name,
            ffi_headers = depset(
                direct = ctx.files.ffi_headers,
                transitive = [abi.php.headers],
            ),
            php_abi = abi,
            profile = profile,
            raw_bindings = bindings.raw,
        ),
    ]
    if OutputGroupInfo in archive:
        providers.append(archive[OutputGroupInfo])
    return providers

_configured_profiler_archive = rule(
    implementation = _configured_profiler_archive_impl,
    attrs = {
        "abi": attr.label(mandatory = True, providers = [PhpRustAbiInfo]),
        "archive": attr.label(mandatory = True, providers = [CcInfo, RustProductProfileInfo]),
        "bindings": attr.label(mandatory = True, providers = [ProfilerBindingsInfo]),
        "ffi_headers": attr.label_list(allow_files = [".h"], mandatory = True),
    },
    provides = [CcInfo, RustProductProfileInfo, RustPhpProfilerInfo],
)

def _platform_profiler_archive_impl(ctx):
    configured = ctx.attr.actual
    actual = configured[0] if type(configured) == "list" else configured
    providers = [
        actual[DefaultInfo],
        actual[CcInfo],
        actual[RustProductProfileInfo],
        actual[RustPhpProfilerInfo],
    ]
    if OutputGroupInfo in actual:
        providers.append(actual[OutputGroupInfo])
    return providers

_platform_profiler_archive = rule(
    implementation = _platform_profiler_archive_impl,
    attrs = {
        "actual": attr.label(
            cfg = single_platform_transition,
            mandatory = True,
            providers = [CcInfo, RustProductProfileInfo, RustPhpProfilerInfo],
        ),
        "matrix_platform": attr.string(mandatory = True),
        "preserve_debug": attr.bool(default = True),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
    },
    provides = [CcInfo, RustProductProfileInfo, RustPhpProfilerInfo],
)

def ddtrace_rust_profiler_target(name, php):
    """Declares a profiler archive configured for exactly one PHP SDK."""
    if native.package_name():
        fail("ddtrace_rust_profiler_target must be called from the workspace root package")

    native_inputs = profiler_native_inputs(
        name = "_" + name + "_native",
        php = php,
        crate_features = _PROFILER_FEATURES,
    )
    profile_input = "_" + name + "_profile_input"
    rust_static_library(
        name = profile_input,
        srcs = native.glob([
            "components-rs/**/*.rs",
            "profiling/**/*.rs",
        ], exclude = ["components-rs/php_sidecar_mockgen/**/*.rs"]),
        aliases = libdatadog_profiler_native_aliases(""),
        compile_data = [
            "Cargo.toml",
            "VERSION",
        ],
        crate_features = _PROFILER_FEATURES,
        crate_name = name.replace("-", "_"),
        crate_root = "components-rs/lib.rs",
        deps = libdatadog_profiler_native_deps("") + [
            native_inputs.build_info,
            native_inputs.ffi,
        ],
        edition = "2021",
        proc_macro_deps = libdatadog_profiler_native_proc_macro_deps(""),
        rustc_flags = [
            ROOT_FEATURE_CHECK,
            "--cfg=php_shared_build",
            "--cfg=standalone_profiler",
            "--cfg=tokio_unstable",
            "--check-cfg=cfg(test)",
            "--check-cfg=cfg(php_shared_build)",
            "--check-cfg=cfg(standalone_profiler)",
            "--check-cfg=cfg(tokio_unstable)",
        ],
    )
    rust_archive = "_" + name + "_rust_archive"
    rust_product_archive(
        name = rust_archive,
        deps = ":" + profile_input,
        product = "profiler",
    )
    _configured_profiler_archive(
        name = name,
        abi = native_inputs.abi,
        archive = ":" + rust_archive,
        bindings = native_inputs.build_info,
        ffi_headers = native_inputs.ffi_headers,
    )

def configured_rust_profiler_target(name, php, matrix_platform):
    """Declares a profiler archive whose public label selects one platform."""
    internal = "_" + name + "_configured"
    ddtrace_rust_profiler_target(
        name = internal,
        php = php,
    )
    _platform_profiler_archive(
        name = name,
        actual = ":" + internal,
        matrix_platform = matrix_platform,
    )
