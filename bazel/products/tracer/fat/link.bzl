"""Versioned monolithic tracer shared-library link targets."""

load("@rules_cc//cc:cc_binary.bzl", "cc_binary")

def _link(name, product_suffix, family = "primary", glibc_only = False):
    if family == "primary":
        dependencies = {
            ":amd64_glibc": ["//bazel/products/tracer:amd64_glibc_php%s_c" % product_suffix],
            ":arm64_glibc": ["//bazel/products/tracer:arm64_glibc_php%s_c" % product_suffix],
        }
        if not glibc_only:
            dependencies.update({
                ":amd64_musl": ["//bazel/products/tracer:amd64_musl_php%s_c" % product_suffix],
                ":arm64_musl": ["//bazel/products/tracer:arm64_musl_php%s_c" % product_suffix],
            })
    elif family in ("release", "shared"):
        dependencies = {
            ":amd64_glibc": ["//bazel/products/tracer:amd64_glibc_%s_php%s_c" % (family, product_suffix)],
            ":arm64_glibc": ["//bazel/products/tracer:arm64_glibc_%s_php%s_c" % (family, product_suffix)],
        }
        glibc_only = True
    elif family == "legacy":
        dependencies = {
            ":amd64_musl": ["//bazel/products/tracer:amd64_musl_legacy_php%s_c" % product_suffix],
            ":arm64_musl": ["//bazel/products/tracer:arm64_musl_legacy_php%s_c" % product_suffix],
        }
    else:
        fail("unknown tracer product family %s" % family)
    cc_binary(
        name = name,
        additional_linker_inputs = [
            ":ddtrace-fat.sym",
            ":ddtrace_fat_symbols_check",
        ],
        linkopts = [
            "-Wl,--build-id=sha1",
            # A fat Linux ddtrace.so also serves as the sidecar executable.
            # Keep its direct entry local while making the ELF header point
            # at it, matching the production config.m4 link contract.
            "-Wl,-e,ddog_spawn_direct_entry",
            "-Wl,--retain-symbols-file=$(location :ddtrace-fat.sym)",
            "-Wl,-soname,ddtrace.so",
            "-ldl",
            "-lm",
            "-lpthread",
        ],
        linkshared = True,
        linkstatic = True,
        target_compatible_with = [
            "@platforms//os:linux",
            "//bazel/platforms:normal",
        ] + (["//bazel/platforms:glibc"] if glibc_only else ["//bazel/platforms:musl"] if family == "legacy" else []),
        deps = ["//:rust_datadog_php"] + select(dependencies),
    )

def ddtrace_fat_links():
    """Links the retained normal PHP 8.5 tracer DSO."""
    _link("ddtrace.so", "85")
