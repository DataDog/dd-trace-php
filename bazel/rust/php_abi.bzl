"""Provider-driven Rust/profiler ABI configuration.

This replaces profiling/build.rs calls to target php-config and its compiled
target executable probe. Bindgen/C compilation consume the returned PHP
CcInfo and declared SDK files; no target program is executed.
"""

load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/php:php_toolchain.bzl", "PhpToolchainInfo")

PhpRustAbiInfo = provider(
    doc = "Explicit PHP ABI inputs and cfgs for Rust and profiler C sources.",
    fields = {
        "c_defines": "Ordered CFG_* defines for profiler C compilation.",
        "config_header": "Generated C header containing the resolved CFG_* values.",
        "crate_features": "Exact resolved root-crate features used for profiler C cfgs.",
        "manifest": "Generated machine-readable PHP ABI and cfg manifest.",
        "php": "The complete configured PhpToolchainInfo.",
        "rust_source": "Generated Rust constants for the selected PHP ABI.",
        "rustc_flags": "Ordered --check-cfg/--cfg flags derived from PHP version and ABI.",
        "sdk_inputs": "depset of PHP headers and SDK files for bindgen/C actions.",
    },
)

_KNOWN_RUST_CFGS = (
    "php7",
    "php8",
    "php_debug",
    "php_frameless",
    "php_gc_status",
    "php_gc_status_extended",
    "php_has_fibers",
    "php_opcache_restart_hook",
    "php_post_startup_cb",
    "php_preload",
    "php_run_time_cache",
    "php_zend_compile_string_has_position",
    "php_zend_mm_set_custom_handlers_ex",
    "php_zts",
    "php_zts_fast_globals",
    "zend_error_observer",
    "zend_error_observer_80",
)

_TARGET_TRIPLES = {
    ("amd64", "glibc"): "x86_64-unknown-linux-gnu",
    ("amd64", "musl"): "x86_64-unknown-linux-musl",
    ("arm64", "glibc"): "aarch64-unknown-linux-gnu",
    ("arm64", "musl"): "aarch64-unknown-linux-musl",
}

def _enabled(value, threshold):
    return value >= threshold

def _bit(value):
    return 1 if value else 0

def _numeric_prefix(value):
    digits = ""
    for index in range(len(value)):
        char = value[index]
        if char not in "0123456789":
            break
        digits += char
    if not digits:
        fail("PHP release component has no numeric prefix: %s" % value)
    return int(digits)

def _require_target_match(ctx, php):
    key = (php.target_arch, php.target_libc)
    expected_triple = _TARGET_TRIPLES.get(key)
    if expected_triple == None:
        fail("unsupported PHP Rust target: %s/%s" % key)
    if php.target_triple != expected_triple:
        fail("PHP SDK target triple %s does not match %s/%s (%s)" % (
            php.target_triple,
            php.target_arch,
            php.target_libc,
            expected_triple,
        ))

    required = ctx.attr._x86_64 if php.target_arch == "amd64" else ctx.attr._aarch64
    if not ctx.target_platform_has_constraint(required[platform_common.ConstraintValueInfo]):
        fail("PHP SDK architecture %s does not match the configured target platform" % php.target_arch)
    required = ctx.attr._glibc if php.target_libc == "glibc" else ctx.attr._musl
    if not ctx.target_platform_has_constraint(required[platform_common.ConstraintValueInfo]):
        fail("PHP SDK libc %s does not match the configured target platform" % php.target_libc)
    target_asan = ctx.target_platform_has_constraint(ctx.attr._asan[platform_common.ConstraintValueInfo])
    if php.asan != target_asan:
        fail("PHP SDK ASan metadata %s does not match the configured target sanitizer" % php.asan)

def _php_rust_abi_config_impl(ctx):
    php_target = ctx.attr.php
    php = php_target[PhpToolchainInfo]
    _require_target_match(ctx, php)
    crate_features = sorted(ctx.attr.crate_features)
    parts = php.version.split(".")
    if len(parts) != 3:
        fail("PHP version must be major.minor.patch, got %s" % php.version)
    major = _numeric_prefix(parts[0])
    minor = _numeric_prefix(parts[1])
    patch = _numeric_prefix(parts[2])
    if major not in [7, 8]:
        fail("unsupported PHP major version %d" % major)
    vernum = major * 10000 + minor * 100 + patch
    if "profiling" in crate_features and vernum < 70100:
        fail("the native profiler requires PHP 7.1 or newer, got %s" % php.version)

    features = {
        "php_frameless": _enabled(vernum, 80400),
        "php_gc_status": _enabled(vernum, 70300),
        "php_gc_status_extended": _enabled(vernum, 80300),
        "php_has_fibers": _enabled(vernum, 80100),
        "php_opcache_restart_hook": _enabled(vernum, 80400),
        "php_post_startup_cb": _enabled(vernum, 70300),
        "php_preload": _enabled(vernum, 70400),
        "php_run_time_cache": _enabled(vernum, 80000),
        "php_zend_compile_string_has_position": _enabled(vernum, 80200),
        "php_zend_mm_set_custom_handlers_ex": _enabled(vernum, 80400),
        "php_zts_fast_globals": _enabled(vernum, 70400),
        "zend_error_observer": _enabled(vernum, 80000),
        "zend_error_observer_80": vernum >= 80000 and vernum < 80100,
    }
    rust_cfgs = ["php%d" % major]
    if php.zts:
        rust_cfgs.append("php_zts")
    if php.debug:
        rust_cfgs.append("php_debug")
    rust_cfgs.extend(sorted([name for name, enabled in features.items() if enabled]))
    rustc_flags = ["--check-cfg=cfg(%s)" % cfg for cfg in _KNOWN_RUST_CFGS]
    rustc_flags.extend(["--cfg=%s" % cfg for cfg in rust_cfgs])

    c_define_pairs = [
        ("CFG_POST_STARTUP_CB", _bit(features["php_post_startup_cb"])),
        ("CFG_PRELOAD", _bit(features["php_preload"])),
        ("CFG_FIBERS", _bit(features["php_has_fibers"])),
        ("CFG_FRAMELESS", _bit(features["php_frameless"])),
        ("CFG_RUN_TIME_CACHE", _bit(features["php_run_time_cache"])),
        ("CFG_STACK_WALKING_TESTS", _bit("stack_walking_tests" in crate_features)),
        ("CFG_TRIGGER_TIME_SAMPLE", _bit("trigger_time_sample" in crate_features)),
        ("CFG_ZEND_ERROR_OBSERVER", _bit(features["zend_error_observer"])),
    ]
    c_defines = ["%s=%d" % pair for pair in c_define_pairs]

    rust_out = ctx.actions.declare_file(ctx.label.name + ".rs")
    header_out = ctx.actions.declare_file(ctx.label.name + ".h")
    manifest_out = ctx.actions.declare_file(ctx.label.name + ".json")
    ctx.actions.write(
        rust_out,
        "\n".join([
            "// Generated from PhpToolchainInfo; do not edit.",
            "pub const PHP_VERSION: &str = \"%s\";" % php.version,
            "pub const PHP_API: u32 = %d;" % php.api,
            "pub const PHP_TARGET_TRIPLE: &str = \"%s\";" % php.target_triple,
            "pub const PHP_ZTS: bool = %s;" % str(php.zts).lower(),
            "pub const PHP_DEBUG: bool = %s;" % str(php.debug).lower(),
            "",
        ]),
    )
    ctx.actions.write(
        header_out,
        "\n".join(["#define %s %d" % pair for pair in c_define_pairs]) + "\n",
    )
    ctx.actions.write(
        manifest_out,
        "{" +
        "\"api\":%d," % php.api +
        "\"crate_features\":[%s]," % ",".join(["\"%s\"" % feature for feature in crate_features]) +
        "\"debug\":%s," % str(php.debug).lower() +
        "\"rust_cfgs\":[%s]," % ",".join(["\"%s\"" % cfg for cfg in rust_cfgs]) +
        "\"target_triple\":\"%s\"," % php.target_triple +
        "\"version\":\"%s\"," % php.version +
        "\"zts\":%s" % str(php.zts).lower() +
        "}\n",
    )

    sdk_inputs = depset(
        [php.sdk],
        transitive = [php.headers],
    )
    return [
        DefaultInfo(files = depset([rust_out, header_out, manifest_out], transitive = [sdk_inputs])),
        PhpRustAbiInfo(
            c_defines = tuple(c_defines),
            config_header = header_out,
            crate_features = tuple(crate_features),
            manifest = manifest_out,
            php = php,
            rust_source = rust_out,
            rustc_flags = tuple(rustc_flags),
            sdk_inputs = sdk_inputs,
        ),
        php_target[CcInfo],
    ]

php_rust_abi_config = rule(
    implementation = _php_rust_abi_config_impl,
    attrs = {
        "crate_features": attr.string_list(allow_empty = True, mandatory = True),
        "php": attr.label(mandatory = True, providers = [PhpToolchainInfo, CcInfo]),
        "_aarch64": attr.label(default = "@platforms//cpu:aarch64"),
        "_asan": attr.label(default = "//bazel/platforms:asan"),
        "_glibc": attr.label(default = "//bazel/platforms:glibc"),
        "_musl": attr.label(default = "//bazel/platforms:musl"),
        "_x86_64": attr.label(default = "@platforms//cpu:x86_64"),
    },
    doc = "Derives profiler cfgs from declared PHP ABI metadata only.",
    provides = [PhpRustAbiInfo, CcInfo],
)
