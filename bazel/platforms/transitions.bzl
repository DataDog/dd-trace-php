"""Transitions used by the public aggregate Linux matrix targets."""

load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")

_PLATFORMS = {
    "linux-amd64-glibc": "//bazel/platforms:linux_amd64_glibc",
    "linux-arm64-glibc": "//bazel/platforms:linux_arm64_glibc",
    "linux-amd64-musl": "//bazel/platforms:linux_amd64_musl",
    "linux-arm64-musl": "//bazel/platforms:linux_arm64_musl",
    "asan-linux-amd64-glibc": "//bazel/platforms:asan_linux_amd64_glibc",
    "asan-linux-arm64-glibc": "//bazel/platforms:asan_linux_arm64_glibc",
}

_NORMAL_PLATFORM_KEYS = (
    "linux-amd64-glibc",
    "linux-arm64-glibc",
    "linux-amd64-musl",
    "linux-arm64-musl",
)

def _linux_matrix_transition_impl(_settings, attr):
    selected = attr.matrix_platforms or _NORMAL_PLATFORM_KEYS
    result = {}
    for key in selected:
        if key not in _PLATFORMS:
            fail("unknown Linux matrix platform %r" % key)
        result[key] = {
            "//command_line_option:platforms": _PLATFORMS[key],
            "@rules_rust//rust/toolchain/channel": "nightly" if key.startswith("asan-") else "stable",
        }
    return result

linux_matrix_transition = transition(
    implementation = _linux_matrix_transition_impl,
    inputs = [],
    outputs = ["//command_line_option:platforms", "@rules_rust//rust/toolchain/channel"],
)

def _single_platform_transition_impl(settings, attr):
    if attr.matrix_platform not in _PLATFORMS:
        fail("unknown Linux matrix platform %r" % attr.matrix_platform)
    return {
        "//command_line_option:platforms": _PLATFORMS[attr.matrix_platform],
        "//command_line_option:strip": "never" if getattr(attr, "preserve_debug", False) else settings["//command_line_option:strip"],
        "@rules_rust//rust/toolchain/channel": "nightly" if attr.matrix_platform.startswith("asan-") else "stable",
    }

single_platform_transition = transition(
    implementation = _single_platform_transition_impl,
    inputs = ["//command_line_option:strip"],
    outputs = ["//command_line_option:platforms", "//command_line_option:strip", "@rules_rust//rust/toolchain/channel"],
)

def _configured_target_impl(ctx):
    configured = ctx.attr.actual
    actual = configured[0] if type(configured) == "list" else configured
    providers = [DefaultInfo(files = actual[DefaultInfo].files)]
    if CcInfo in actual:
        providers.append(actual[CcInfo])
    if OutputGroupInfo in actual:
        providers.append(actual[OutputGroupInfo])
    return providers

configured_target = rule(
    implementation = _configured_target_impl,
    attrs = {
        "actual": attr.label(cfg = single_platform_transition, mandatory = True),
        "matrix_platform": attr.string(mandatory = True),
        "preserve_debug": attr.bool(),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
    },
)

def _matrix_aggregate_impl(ctx):
    transitive = []
    for configured_targets in ctx.split_attr.targets.values():
        for target in configured_targets:
            transitive.append(target[DefaultInfo].files)
    return [DefaultInfo(files = depset(transitive = transitive))]

matrix_aggregate = rule(
    implementation = _matrix_aggregate_impl,
    attrs = {
        "matrix_platforms": attr.string_list(),
        "targets": attr.label_list(cfg = linux_matrix_transition),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
    },
    doc = "Builds its targets under every selected Linux architecture/libc platform.",
)
