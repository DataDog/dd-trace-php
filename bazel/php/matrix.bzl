"""Complete Linux PHP ABI matrix and deterministic JSON manifest export."""

load(":php_versions.bzl", "NORMAL_PHP_VERSIONS", "PHP_RELEASES", "PHP_SOURCES", "PROFILER_PHP_VERSIONS", "source_key")
load(":profiles.bzl", "profile")

_ARCHES = ("amd64", "arm64")
_GLIBC_PROFILES = {
    "7.0": ("debug-zts", "debug", "nts", "zts"),
    "7.1": ("debug-zts", "debug", "nts", "zts"),
    "7.2": ("debug-zts", "debug", "nts", "zts"),
    "7.3": ("debug-zts", "debug", "nts", "zts"),
    "7.4": ("debug", "nts", "zts", "debug-zts-asan"),
    "8.0": ("debug", "nts", "zts", "debug-zts-asan"),
    "8.1": ("debug", "nts", "zts", "debug-zts-asan"),
    "8.2": ("debug", "nts", "zts", "debug-zts-asan"),
    "8.3": ("debug", "nts", "zts", "debug-zts-asan", "nts-asan"),
    "8.4": ("debug", "nts", "zts", "debug-zts-asan", "nts-asan"),
    "8.5": ("debug", "nts", "zts", "debug-zts-asan", "nts-asan"),
}

def _patches(minor, runtime_profile):
    if runtime_profile == "alpine":
        root = "//:dockerfiles/ci/alpine_compile_extension/"
        patches = ()
        if int(minor.replace(".", "")) < 81:
            patches = patches + ("0001-Backport-0a39890c-Fix-libxml2-2.12-build-due-to-API-.patch",)
        if minor == "7.0":
            patches = patches + ("0001-Sync-callback-signature-with-libxml2-2.9.8.patch",)
        return tuple([root + patch for patch in patches])
    if runtime_profile != "bookworm":
        return ()

    # The source CI directory has no Bazel package of its own. These source
    # files therefore belong to the workspace root package.
    root = "//:dockerfiles/ci/bookworm/"
    patches = {
        "7.0": ("php-7.0/0001-fix-broken-sprintf-detection.patch", "php-7.0/0001-fix-build-scripts.patch", "php-7.0/0001-Sync-callback-signature-with-libxml2-2.9.8.patch", "php-7.0/0001-Fix-stream_cookie_seeker-signature-under-musl.patch", "0001-Fixed-incorrect-behavior-of-internal-memory-debugger.patch", "0001-Fix-OpenSSL-3.patch"),
        "7.1": ("php-7.1/0001-fix-broken-sprintf-detection.patch", "php-7.1/0001-fix-build-scripts.patch", "php-7.1/0001-Change-UBool-to-bool-for-equality-operators-in-ICU-7.patch", "php-7.1/0001-Fix-stream_cookie_seeker-signature-under-musl.patch", "0001-Fixed-incorrect-behavior-of-internal-memory-debugger.patch", "0001-Fix-OpenSSL-3.patch"),
        "7.2": ("php-7.2/0001-fix-broken-sprintf-detection.patch", "php-7.2/0001-fix-build-scripts.patch", "php-7.2/0001-Change-UBool-to-bool-for-equality-operators-in-ICU-7.patch", "php-7.2/0001-Fix-stream_cookie_seeker-signature-under-musl.patch", "0001-Fixed-incorrect-behavior-of-internal-memory-debugger.patch", "0001-Fix-OpenSSL-3.patch"),
        "7.3": ("php-7.3/0001-fix-build-scripts.patch", "php-7.3/0001-Change-UBool-to-bool-for-equality-operators-in-ICU-7.patch", "0001-Fix-OpenSSL-3.patch"),
        "7.4": ("php-7.4/0001-fix-build-scripts.patch", "0001-Fix-OpenSSL-3.patch"),
        "8.0": ("0001-Better-support-for-cross-compilation.patch", "php-8.0/0001-Introduce-DD_IGNORE_ARGINFO_ZPP_CHECK-env-var-to-ski.patch", "php-8.0/0001-Fix-memory-leak-in-zend_wrong_callback_error.patch", "php-8.0/0001-Disable-ZEND_RC_MOD_CHECK-while-loading-shared-exten.patch", "0001-Fix-GH-10611-fpm_env_init_main-leaks-environ.patch", "0001-Fix-OpenSSL-3.patch"),
        "8.1": ("php-8.1/0001-Introduce-DD_IGNORE_ARGINFO_ZPP_CHECK-env-var-to-ski.patch", "php-8.1/0002-fix-tsrm-jit-aarch64.patch", "php-8.1/0001-Disable-inlining-and-inter-procedure-analyses-for-ze.patch", "0001-Disable-ZEND_RC_MOD_CHECK-while-loading-shared-exten.patch"),
        "8.2": ("php-8.2/0001-Introduce-DD_IGNORE_ARGINFO_ZPP_CHECK-env-var-to-ski.patch", "0001-Disable-ZEND_RC_MOD_CHECK-while-loading-shared-exten.patch", "0001-Delete-timers-on-fork.patch"),
        "8.3": ("php-8.3/0001-Introduce-DD_IGNORE_ARGINFO_ZPP_CHECK-env-var-to-ski.patch",),
    }.get(minor, ())
    return tuple([root + patch for patch in patches])

def _products(minor):
    return {
        "tracer": struct(supported = True, reason = ""),
        "loader": struct(supported = True, reason = ""),
        "sidecar": struct(supported = True, reason = ""),
        "appsec": struct(supported = True, reason = ""),
        "profiler": struct(supported = minor in PROFILER_PHP_VERSIONS, reason = "profiler starts at PHP 7.1" if minor not in PROFILER_PHP_VERSIONS else ""),
    }

def _patch_operations(patches):
    operations = []
    for patch in patches:
        mode = "patch_p1"
        target = ""
        if any([needle in patch for needle in ("Fix-stream_cookie", "Better-support", "Introduce-DD", "Fix-memory-leak", "Disable-ZEND", "Disable-inlining", "fix-tsrm")]):
            mode = "git_apply"
        elif patch.endswith("0001-Fixed-incorrect-behavior-of-internal-memory-debugger.patch"):
            mode = "patch_file"
            target = "Zend/zend_alloc.c"
        elif patch.endswith("0001-Fix-OpenSSL-3.patch"):
            mode = "patch_file"
            target = "ext/openssl/openssl.c"
        elif patch.endswith("0001-Delete-timers-on-fork.patch"):
            mode = "patch_file"
            target = "Zend/zend_max_execution_timer.c"
        operations.append(struct(label = patch, mode = mode, target = target))
    return tuple(operations)

def _triple(arch, libc):
    if arch == "amd64":
        return "x86_64-unknown-linux-gnu" if libc == "glibc" else "x86_64-unknown-linux-musl"
    return "aarch64-unknown-linux-gnu" if libc == "glibc" else "aarch64-unknown-linux-musl"

def _compiler_flags(minor, runtime_profile):
    """CI CFLAGS that affect configure tests and the resulting ABI."""
    numeric = int(minor.replace(".", ""))
    if runtime_profile == "bookworm" and numeric <= 73:
        return ("-Wno-implicit-function-declaration", "-DHAVE_POSIX_READDIR_R=1", "-DHAVE_OLD_READDIR_R=0", "-DTRUE=1", "-DFALSE=0")
    if runtime_profile != "alpine":
        return ()
    flags = []
    if numeric <= 74:
        flags.extend(["-DHAVE_POSIX_READDIR_R=1", "-DHAVE_OLD_READDIR_R=0"])
    if numeric < 74:
        flags.extend(["-DCOOKIE_SEEKER_USES_OFF64_T=1", "-D__off64_t=ssize_t"])
    elif numeric <= 81:
        flags.extend(["-DCOOKIE_SEEKER_USES_OFF64_T=1", "-Doff64_t=ssize_t"])
    return tuple(flags)

def _source_edits(runtime_profile):
    if runtime_profile != "alpine":
        return ()

    # The installer applies this only to whichever generated DynASM/JIT file
    # exists in the selected PHP source tree.
    return (struct(
        kind = "replace_if_present",
        paths = ("ext/opcache/jit/zend_jit_x86.dasc", "ext/opcache/jit/zend_jit_ir.c"),
        find = ': "=a" (ti))',
        replace = ': "=D" (ti))',
    ),)

def _record(minor, arch, libc, profile_name, runtime_profile, shared = False):
    details = profile(minor, profile_name, shared = shared, runtime_profile = runtime_profile)
    source = source_key(minor, runtime_profile)
    release = PHP_SOURCES[source]
    return struct(
        name = "php_%s_%s_%s_%s_%s%s" % (minor.replace(".", "_"), arch, libc, runtime_profile.replace("-", "_"), profile_name.replace("-", "_"), "_shared" if shared else ""),
        minor = minor,
        version = release.version,
        api = release.api,
        source_key = source,
        source = "@%s//:srcs" % source,
        source_url = release.url,
        source_sha256 = release.sha256,
        patches = _patches(minor, runtime_profile),
        patch_operations = _patch_operations(_patches(minor, runtime_profile)),
        arch = arch,
        libc = libc,
        target_triple = _triple(arch, libc),
        runtime_profile = runtime_profile,
        profile = profile_name,
        shared_build = shared,
        sanitizer = "asan" if details.asan else "none",
        configure_args = details.configure_args,
        compiler_flags = _compiler_flags(minor, runtime_profile),
        source_edits = _source_edits(runtime_profile),
        host_probe_results = {"ac_cv_func_setpgrp_void": "yes", "ac_cv_func_setvbuf_reversed": "no", "php_cv_cc_rpath": "yes"},
        sapis = details.sapis if not details.asan else tuple([s for s in details.sapis if s != "apache2handler"]),
        bundled_extensions = details.bundled_extensions,
        shared_extensions = details.shared_extensions,
        historical_sapis = details.historical_sapis if not details.asan else tuple([s for s in details.historical_sapis if s != "apache2handler"]),
        historical_bundled_extensions = details.historical_bundled_extensions,
        historical_shared_extensions = details.historical_shared_extensions,
        expected_artifacts = (
            "sdk/bin/php-config",
            "sdk/include/php/main/php.h",
            "sdk/include/php/main/php_config.h",
            "sdk/include/php/main/php_version.h",
            "sdk/include/php/Zend/zend.h",
            "sdk/metadata/import.lock.json",
            "sdk/metadata/observed.json",
            "sdk/metadata/provenance.json",
        ),
        products = _products(minor),
        supported = True,
    )

def _build_matrix():
    records = {}
    for minor in NORMAL_PHP_VERSIONS:
        for arch in _ARCHES:
            for profile_name in _GLIBC_PROFILES[minor]:
                record = _record(minor, arch, "glibc", profile_name, "bookworm")
                records[record.name] = record

            # CentOS 7 release images are a distinct ABI/runtime family: their
            # source pin (notably 8.5.7), SAPI set and configure options differ.
            for profile_name in ("debug", "nts", "zts"):
                record = _record(minor, arch, "glibc", profile_name, "release")
                records[record.name] = record
            if minor in ("7.4", "8.0"):
                for profile_name in _GLIBC_PROFILES[minor]:
                    record = _record(minor, arch, "glibc", profile_name, "bookworm", shared = True)
                    records[record.name] = record
            for profile_name in ("nts", "zts"):
                record = _record(minor, arch, "musl", profile_name, "alpine")
                records[record.name] = record
            if minor == "8.0":
                for profile_name in ("debug-zts", "debug", "nts"):
                    record = _record(minor, arch, "musl", profile_name, "alpine-legacy")
                    records[record.name] = record
    return records

PHP_MATRIX = _build_matrix()

def php_source_repositories():
    result = {}
    for record in PHP_MATRIX.values():
        result[record.source_key] = True
    return tuple(sorted(result.keys()))

def php_matrix_targets(runtime_rule, name_prefix = "php_"):
    """Instantiates concrete targets from the one matrix; used by root BUILD."""
    for record in [PHP_MATRIX[name] for name in sorted(PHP_MATRIX.keys())]:
        runtime_rule(name = name_prefix + record.name[4:], matrix = record)

def _matrix_manifest_impl(ctx):
    records = []
    for name in sorted(PHP_MATRIX.keys()):
        record = PHP_MATRIX[name]
        records.append({
            "api": record.api,
            "arch": record.arch,
            "bundled_extensions": record.bundled_extensions,
            "compiler_flags": record.compiler_flags,
            "configure_args": record.configure_args,
            "expected_artifacts": record.expected_artifacts,
            "host_probe_results": record.host_probe_results,
            "libc": record.libc,
            "minor": record.minor,
            "name": record.name,
            "patches": record.patches,
            "patch_operations": [{"label": operation.label, "mode": operation.mode, "target": operation.target} for operation in record.patch_operations],
            "products": {key: {"reason": value.reason, "supported": value.supported} for key, value in record.products.items()},
            "profile": record.profile,
            "runtime_profile": record.runtime_profile,
            "sanitizer": record.sanitizer,
            "sapis": record.sapis,
            "shared_extensions": record.shared_extensions,
            "historical_sapis": record.historical_sapis,
            "historical_bundled_extensions": record.historical_bundled_extensions,
            "historical_shared_extensions": record.historical_shared_extensions,
            "source": record.source,
            "source_edits": [{"find": edit.find, "kind": edit.kind, "paths": edit.paths, "replace": edit.replace} for edit in record.source_edits],
            "source_key": record.source_key,
            "source_sha256": record.source_sha256,
            "source_url": record.source_url,
            "supported": record.supported,
            "target_triple": record.target_triple,
            "version": record.version,
        })
    ctx.actions.write(ctx.outputs.out, json.encode({"schema_version": 1, "records": records}) + "\n")
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

matrix_manifest = rule(implementation = _matrix_manifest_impl, outputs = {"out": "%{name}.json"})
