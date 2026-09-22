"""Configuration boundary for Cargo-equivalent production Rust archives."""

load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/rust:dependency_guard.bzl", "reject_aws_lc_sys_aspect")

RustProductProfileInfo = provider(
    doc = "The authoritative Cargo-compatible profile selected for a Rust product.",
    fields = {
        "cargo_profile": "Cargo profile name represented by this configured target.",
        "rustc_flags": "Required non-exec rustc code-generation flags.",
    },
)

_EXTRA_RUSTC_FLAGS = "@rules_rust//rust/settings:extra_rustc_flags"
_LTO = "@rules_rust//rust/settings:lto"
_PLATFORMS = "//command_line_option:platforms"

# Keep this in lockstep with .github/workflows/prof_asan.yml. The ASan
# toolchain builds std and panic_abort from the locked nightly source with the
# same sanitizer, frame-pointer, and panic settings as the product closure.
_ASAN_RUSTC_FLAGS = [
    "-Zsanitizer=address",
    "-Cforce-frame-pointers=yes",
    "-Clink-arg=-fsanitize=address",
    "-Clink-arg=-shared-libasan",
]

# Both custom Cargo profiles inherit `release`. The tracer explicitly selects
# limited debug information, while the profiler inherits line tables only.
# rules_rust's LTO setting is separate because it emits linker-plugin bitcode
# for rlibs and fat LTO only for final linkable artifacts, matching Cargo.
_RELEASE_RUSTC_FLAGS = [
    "-Ccodegen-units=1",
    "-Cdebug-assertions=no",
    "-Copt-level=3",
    "-Coverflow-checks=no",
    "-Cpanic=abort",
    "-Cstrip=none",
]

_PRODUCT_PROFILES = {
    "profiler": struct(
        cargo_profile = "profiler-release",
        debug_flag = "-Cdebuginfo=line-tables-only",
    ),
    "tracer": struct(
        cargo_profile = "tracer-release",
        debug_flag = "-Cdebuginfo=1",
    ),
}

def _product_rustc_flags(product):
    return _RELEASE_RUSTC_FLAGS + [_PRODUCT_PROFILES[product].debug_flag]

def _product_profile_transition_impl(settings, attr):
    if attr.product not in _PRODUCT_PROFILES:
        fail("unmodeled Rust product profile: %s" % attr.product)
    rustc_flags = list(settings[_EXTRA_RUSTC_FLAGS]) + _product_rustc_flags(attr.product)
    if any(["asan_linux_" in str(platform) for platform in settings[_PLATFORMS]]):
        rustc_flags.extend(_ASAN_RUSTC_FLAGS)
    return {
        "//command_line_option:compilation_mode": "opt",
        "//command_line_option:strip": "never",
        _EXTRA_RUSTC_FLAGS: rustc_flags,
        _LTO: "fat",
    }

_product_profile_transition = transition(
    implementation = _product_profile_transition_impl,
    inputs = [_EXTRA_RUSTC_FLAGS, _PLATFORMS],
    outputs = [
        "//command_line_option:compilation_mode",
        "//command_line_option:strip",
        _EXTRA_RUSTC_FLAGS,
        _LTO,
    ],
)

def _rust_product_archive_impl(ctx):
    archive = ctx.attr.deps
    if type(archive) == "list":
        if len(archive) != 1:
            fail("product profile transition resolved %d archive variants" % len(archive))
        archive = archive[0]
    providers = [
        archive[DefaultInfo],
        archive[CcInfo],
        RustProductProfileInfo(
            cargo_profile = _PRODUCT_PROFILES[ctx.attr.product].cargo_profile,
            rustc_flags = tuple(_product_rustc_flags(ctx.attr.product)),
        ),
    ]
    if OutputGroupInfo in archive:
        providers.append(archive[OutputGroupInfo])
    return providers

rust_product_archive = rule(
    implementation = _rust_product_archive_impl,
    attrs = {
        "deps": attr.label(
            aspects = [reject_aws_lc_sys_aspect],
            cfg = _product_profile_transition,
            mandatory = True,
            providers = [CcInfo],
        ),
        "product": attr.string(
            mandatory = True,
            values = sorted(_PRODUCT_PROFILES.keys()),
        ),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
    },
    doc = "Applies a complete release profile and required dependency guards to one production Rust archive graph.",
    provides = [CcInfo, RustProductProfileInfo],
)
