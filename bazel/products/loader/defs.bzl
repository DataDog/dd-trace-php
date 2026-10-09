"""Native Bazel targets for the universal Datadog PHP library loader."""

load("@rules_cc//cc:cc_binary.bzl", "cc_binary")
load("//bazel/packaging:bundle.bzl", "deterministic_ssi_bundle", "ssi_payload")
load("//bazel/platforms:transitions.bzl", "configured_target")
load(":native_smoke.bzl", "loader_native_smoke")
load(":verify.bzl", "loader_elf_check", "loader_ini", "loader_metadata", "loader_version_header", "split_debug_elf")

_VERSION = "//:VERSION"

_VARIANTS = (
    struct(architecture = "x86_64", name = "amd64_glibc", os_path = "linux-gnu", php = "//bazel/php:php_8_5_amd64_glibc_bookworm_nts", platform = "linux-amd64-glibc"),
    struct(architecture = "aarch64", name = "arm64_glibc", os_path = "linux-gnu", php = "//bazel/php:php_8_5_arm64_glibc_bookworm_nts", platform = "linux-arm64-glibc"),
    struct(architecture = "x86_64", name = "amd64_musl", os_path = "linux-musl", php = "//bazel/php:php_8_5_amd64_musl_alpine_nts", platform = "linux-amd64-musl"),
    struct(architecture = "aarch64", name = "arm64_musl", os_path = "linux-musl", php = "//bazel/php:php_8_5_arm64_musl_alpine_nts", platform = "linux-arm64-musl"),
)

_SOURCES = [
    "//:loader/compat_php.c",
    "//:loader/compat_php.h",
    "//:loader/dd_library_loader.c",
    "//:loader/dd_library_loader_module.c",
    "//:loader/php_dd_library_loader.h",
]

def _loader_variant(variant):
    compiled = "_%s_compiled.so" % variant.name
    unstripped = "_%s_unstripped" % variant.name
    binary = "_%s_binary" % variant.name
    debug = "_%s_debug" % variant.name

    cc_binary(
        name = compiled,
        srcs = _SOURCES + [":_loader_version_header"],
        copts = [
            "-std=gnu11",
            "-O2",
            "-g",
            "-Wall",
            "-Wextra",
            "-Werror",
            "-fvisibility=hidden",
            "-include",
            "bazel/products/loader/loader_version.h",
        ],
        defines = [
            "_GNU_SOURCE",
        ],
        linkopts = [
            "-ldl",
            "-lpthread",
            "-Wl,-soname,dd_library_loader.so",
        ],
        linkshared = True,
        visibility = ["//visibility:private"],
        deps = [variant.php],
    )
    configured_target(
        name = unstripped,
        actual = ":" + compiled,
        matrix_platform = variant.platform,
        preserve_debug = True,
        visibility = ["//visibility:private"],
    )
    split_debug_elf(
        name = variant.name,
        binary = ":" + unstripped,
    )
    native.filegroup(
        name = binary,
        srcs = [":" + variant.name],
        output_group = "binary",
        visibility = ["//visibility:private"],
    )
    native.filegroup(
        name = debug,
        srcs = [":" + variant.name],
        output_group = "debug",
        visibility = ["//visibility:private"],
    )
    loader_elf_check(
        name = variant.name + "_elf_check",
        architecture = variant.architecture,
        binary = ":" + binary,
        debug = ":" + debug,
        libc = "glibc" if variant.os_path == "linux-gnu" else "musl",
        version = _VERSION,
    )
    loader_ini(
        name = variant.name + "_ini",
        os_path = variant.os_path,
    )
    loader_metadata(
        name = variant.name + "_metadata",
        php = variant.php,
        version = _VERSION,
    )

def loader_matrix():
    """Emits one universal loader for each Linux architecture/libc pair."""
    loader_version_header(
        name = "_loader_version_header",
        version = _VERSION,
    )
    outputs = []
    for variant in _VARIANTS:
        _loader_variant(variant)
        outputs.extend([
            ":" + variant.name,
            ":" + variant.name + "_elf_check",
            ":" + variant.name + "_ini",
            ":" + variant.name + "_metadata",
        ])
    native.filegroup(
        name = "loader_all",
        srcs = outputs,
    )
    loader_native_smoke(
        name = "amd64_glibc_native_smoke",
        expected_arch = "amd64",
        exec_compatible_with = ["@platforms//cpu:x86_64"],
        loader = ":_amd64_glibc_binary",
        version = _VERSION,
    )
    loader_native_smoke(
        name = "arm64_glibc_native_smoke",
        expected_arch = "arm64",
        exec_compatible_with = ["@platforms//cpu:aarch64"],
        loader = ":_arm64_glibc_binary",
        version = _VERSION,
    )
    native.filegroup(
        name = "loader_native_smoke_all",
        srcs = [
            ":amd64_glibc_native_smoke",
            ":arm64_glibc_native_smoke",
        ],
    )

def loader_stage(name, variant, os_path):
    """Creates one narrow, real loader archive for an architecture/libc ABI."""
    binary = "%s/loader/dd_library_loader.so" % os_path
    ssi_payload(
        name = name + "_payload",
        srcs = {
            ":_%s_binary" % variant: binary,
            ":_%s_debug" % variant: binary + ".debug",
            ":%s_ini" % variant: "%s/loader/dd_library_loader.ini" % os_path,
            ":%s_metadata" % variant: "%s/loader/metadata.json" % os_path,
        },
        executable_paths = [binary],
        required_paths = [
            "%s/loader/dd_library_loader.ini" % os_path,
            binary,
            binary + ".debug",
            "%s/loader/metadata.json" % os_path,
            "version",
        ],
        version = "//:VERSION",
    )
    deterministic_ssi_bundle(
        name = name,
        executable_paths = [binary],
        payload = ":" + name + "_payload",
        prefix = "dd-library-php-loader-stage",
        validation = [":" + variant + "_elf_check"],
    )
