"""Versioned monolithic tracer shared-library link targets."""

load("@rules_cc//cc:cc_binary.bzl", "cc_binary")
load("//bazel/php:product_matrix.bzl", "product_matrix_rows")

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

def _link_spec(row):
    """Maps one normalized PHP row to its shared configured link target."""
    minor = row.php_minor.replace(".", "")
    profile = row.abi_profile.replace("-", "_")
    profile_suffix = "" if profile == "nts" else "_" + profile
    if row.sdk_family == "release":
        family = "release"
    elif row.shared_build:
        family = "shared"
    elif row.sdk_family == "alpine-legacy":
        family = "legacy"
    else:
        family = "primary"

    product_suffix = minor + profile_suffix
    if family == "primary":
        name = "ddtrace.so" if minor == "85" and profile == "nts" else "ddtrace_php%s%s.so" % (minor, profile_suffix)
    else:
        name = "ddtrace_%s_php%s%s.so" % (family, minor, profile_suffix)
    return struct(
        family = family,
        glibc_only = family == "primary" and row.target_libc == "glibc" and row.debug,
        name = name,
        product_suffix = product_suffix,
    )

def ddtrace_fat_links():
    """Links the normal tracer DSOs selected by the canonical PHP matrix."""
    specs = {}
    for row in product_matrix_rows():
        if not row.product_labels["tracer"]:
            continue
        spec = _link_spec(row)
        previous = specs.get(spec.name)
        if previous:
            if previous != spec:
                fail("inconsistent tracer link target derived for %s" % spec.name)
            continue
        specs[spec.name] = spec

    for name in sorted(specs.keys()):
        spec = specs[name]
        _link(
            spec.name,
            spec.product_suffix,
            family = spec.family,
            glibc_only = spec.glibc_only,
        )
