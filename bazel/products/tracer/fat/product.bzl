"""Published normal-profile monolithic tracer products."""

load("//bazel/php:product_matrix.bzl", "product_matrix_rows")
load("//bazel/platforms:transitions.bzl", "configured_target")
load("//bazel/products/tracer/fat:native_smoke.bzl", "ddtrace_native_smoke")
load("//bazel/products/tracer/fat:verify.bzl", "split_tracer_debug", "tracer_fat_elf_check")

_PRODUCT_LABEL_PREFIX = "//bazel/products/tracer:"
_RETAINED_PRODUCT = "ddtrace_fat_amd64_glibc_php85"

def _product_name(row):
    labels = row.product_labels["tracer"]
    if len(labels) != 1 or not labels[0].startswith(_PRODUCT_LABEL_PREFIX):
        fail("invalid normalized tracer label for %s: %s" % (row.name, labels))
    return labels[0][len(_PRODUCT_LABEL_PREFIX):]

def _link_target(row):
    """Returns the shared link label instantiated from the same matrix row."""
    minor = row.php_minor.replace(".", "")
    profile = row.abi_profile.replace("-", "_")
    suffix = "" if profile == "nts" else "_" + profile
    if row.sdk_family == "release":
        return "//bazel/products/tracer/fat:ddtrace_release_php%s%s.so" % (minor, suffix)
    if row.shared_build:
        return "//bazel/products/tracer/fat:ddtrace_shared_php%s%s.so" % (minor, suffix)
    if row.sdk_family == "alpine-legacy":
        return "//bazel/products/tracer/fat:ddtrace_legacy_php%s%s.so" % (minor, suffix)
    if minor == "85" and profile == "nts":
        return "//bazel/products/tracer/fat:ddtrace.so"
    return "//bazel/products/tracer/fat:ddtrace_php%s%s.so" % (minor, suffix)

def _publish_product(row, outputs):
    product = _product_name(row)
    unstripped = "_%s_unstripped" % product
    binary = product + "_binary"
    debug = product + "_debug"
    check = product + "_check"

    configured_target(
        name = unstripped,
        actual = _link_target(row),
        matrix_platform = row.matrix_platform,
        preserve_debug = True,
        visibility = ["//visibility:private"],
    )
    native.filegroup(
        name = binary,
        srcs = [":" + product],
        output_group = "binary",
    )
    native.filegroup(
        name = debug,
        srcs = [":" + product],
        output_group = "debug",
    )
    split_tracer_debug(
        name = product,
        binary = ":" + unstripped,
    )
    tracer_fat_elf_check(
        name = check,
        architecture = "x86_64" if row.target_arch == "amd64" else "aarch64",
        binary = ":" + binary,
        debug = ":" + debug,
        expected_symbols = "//bazel/products/tracer/fat:ddtrace-fat.sym",
        libc = row.target_libc,
    )
    outputs.extend([":" + product, ":" + check])

    # The locked native execution closure currently supplies PHP 8.5 glibc.
    # Keep these checks separate from the host-neutral artifact aggregate.
    if (row.php_minor == "8.5" and
        row.sdk_family == "bookworm" and
        row.abi_profile == "nts" and
        row.target_libc == "glibc" and
        not row.shared_build):
        smoke = product + "_native_smoke"
        smoke_actual = "_" + smoke
        ddtrace_native_smoke(
            name = smoke_actual,
            exec_compatible_with = [
                "@platforms//cpu:%s" % ("x86_64" if row.target_arch == "amd64" else "aarch64"),
            ],
            extension = ":" + binary,
            tracer = ":%s_%s_php85_c" % (row.target_arch, row.target_libc),
            version = "//:VERSION",
        )
        configured_target(
            name = smoke,
            actual = ":" + smoke_actual,
            matrix_platform = row.matrix_platform,
        )

def ddtrace_fat_matrix():
    """Publishes the reviewed amd64 glibc PHP 8.5 normal tracer product."""
    outputs = []
    emitted = 0
    names = {}
    for row in product_matrix_rows():
        if not row.product_labels["tracer"]:
            continue
        product = _product_name(row)
        if product != _RETAINED_PRODUCT:
            continue
        if product in names:
            fail("duplicate normalized tracer product label: %s" % product)
        names[product] = True
        _publish_product(row, outputs)
        emitted += 1

    if emitted != 1:
        fail("expected retained tracer product %s exactly once, got %d" % (_RETAINED_PRODUCT, emitted))
    native.filegroup(
        name = "ddtrace_fat_all",
        srcs = outputs,
    )
