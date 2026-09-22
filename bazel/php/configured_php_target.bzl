"""Provider-preserving configured PHP target wrapper."""

load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/platforms:transitions.bzl", "single_platform_transition")
load(":php_toolchain.bzl", "PhpToolchainInfo")

def _configured_php_target_impl(ctx):
    actuals = ctx.attr.actual
    if len(actuals) != 1:
        fail("configured PHP wrapper expected one transitioned target, got %d" % len(actuals))
    actual = actuals[0]
    providers = [actual[DefaultInfo], actual[PhpToolchainInfo], actual[CcInfo]]
    if OutputGroupInfo in actual:
        providers.append(actual[OutputGroupInfo])
    return providers

configured_php_target = rule(
    implementation = _configured_php_target_impl,
    attrs = {
        "actual": attr.label(
            cfg = single_platform_transition,
            mandatory = True,
            providers = [PhpToolchainInfo, CcInfo],
        ),
        "matrix_platform": attr.string(mandatory = True),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
    },
)
