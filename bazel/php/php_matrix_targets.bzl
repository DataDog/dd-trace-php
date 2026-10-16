"""Instantiates the PHP matrix from digest-locked OCI header SDKs."""

load("//bazel/dependencies/php_oci:records.bzl", "PHP_OCI_COMPATIBLE_ALIASES", "PHP_OCI_IMPORTS", "PHP_OCI_UNAVAILABLE")
load(":configured_php_target.bzl", "configured_php_target")
load(":matrix.bzl", "PHP_MATRIX")
load(":php_toolchain.bzl", "php_sdk_snapshot")

def _platform(record):
    prefix = "asan-" if _is_asan(record.profile) else ""
    return "%slinux-%s-%s" % (prefix, record.arch, record.libc)

def _is_debug(profile):
    return profile in ("debug", "debug-zts", "debug-zts-asan")

def _is_zts(profile):
    return profile in ("zts", "debug-zts", "debug-zts-asan")

def _is_asan(profile):
    return profile in ("debug-zts-asan", "nts-asan")

def _image_family(record):
    if record.runtime_profile == "release":
        return "centos7"
    if record.runtime_profile == "alpine":
        return "alpine322"
    if record.runtime_profile == "alpine-legacy":
        return "alpine_legacy"
    if record.name.endswith("_shared"):
        return "bookworm_shared"
    return "bookworm"

def _import_by_repo(repo_name):
    for key, record in PHP_OCI_IMPORTS.items():
        if record.repo_name == repo_name:
            return struct(key = key, record = record)
    fail("compatible PHP SDK alias references unknown repository %s" % repo_name)

def _selected_import(key):
    if key in PHP_OCI_IMPORTS:
        return struct(key = key, record = PHP_OCI_IMPORTS[key])
    if key in PHP_OCI_COMPATIBLE_ALIASES:
        return _import_by_repo(PHP_OCI_COMPATIBLE_ALIASES[key].repo_name)
    return None

def _validate_import(matrix_record, selected):
    imported = selected.record
    expected = {
        "php_api": matrix_record.api,
        "debug": _is_debug(matrix_record.profile),
        "zts": _is_zts(matrix_record.profile),
        "asan": _is_asan(matrix_record.profile),
        "shared_build": matrix_record.name.endswith("_shared"),
        "target_libc": matrix_record.libc,
        "target_triple": matrix_record.target_triple,
    }
    for field, wanted in expected.items():
        actual = getattr(imported, field)
        if actual != wanted:
            fail("PHP SDK %s %s mismatch: matrix=%r OCI=%r" % (matrix_record.name, field, wanted, actual))

def _snapshot(name, matrix_record, selected, derive_debug = False, unavailable_image_digest = ""):
    imported = selected.record
    repo = "@%s//:" % imported.repo_name
    php_sdk_snapshot(
        name = name,
        api = imported.php_api,
        asan = imported.asan,
        base_debug = imported.debug,
        base_profile = selected.key[3],
        config = repo + "config",
        configuration_name = matrix_record.name,
        debug = _is_debug(matrix_record.profile),
        declared_source_version = matrix_record.version,
        derive_debug = derive_debug,
        extensions = repo + "extensions",
        headers = repo + "headers",
        image_family = _image_family(matrix_record),
        libs = repo + "libs",
        lock = repo + "lock",
        minor = matrix_record.minor,
        nts = not imported.zts,
        observed = repo + "observed",
        php_symbol_reference = repo + "php_symbol_reference",
        provenance = [repo + "provenance"],
        runtime = repo + "runtime",
        runtime_profile = matrix_record.profile,
        sdk_root = repo + "sdk_root",
        sdk_source_family = selected.key[0],
        shared_build = imported.shared_build,
        source_image_digest = imported.index_digest,
        target_arch = matrix_record.arch,
        target_libc = imported.target_libc,
        target_triple = imported.target_triple,
        unavailable_image_digest = unavailable_image_digest,
        version = imported.observed_image_version,
        zend_extension_api = imported.zend_extension_api,
        zend_module_api = imported.zend_module_api,
        zts = imported.zts,
        tags = ["manual"],
        visibility = ["//visibility:private"],
    )

def _derived_debug_fallback(name, matrix_record, unavailable):
    base_key = ("alpine322", matrix_record.minor, matrix_record.arch, "zts" if _is_zts(matrix_record.profile) else "nts")
    selected = _selected_import(base_key)
    if not selected:
        fail("missing arm64 base SDK for derived legacy debug headers: %r" % (base_key,))
    imported = selected.record
    checks = {
        "php_api": matrix_record.api,
        "zts": _is_zts(matrix_record.profile),
        "asan": False,
        "target_libc": matrix_record.libc,
        "target_triple": matrix_record.target_triple,
    }
    for field, wanted in checks.items():
        actual = getattr(imported, field)
        if actual != wanted:
            fail("derived PHP SDK %s base %s mismatch: matrix=%r OCI=%r" % (matrix_record.name, field, wanted, actual))
    if imported.debug:
        fail("derived PHP debug SDK base must have ZEND_DEBUG disabled")
    _snapshot(
        name,
        matrix_record,
        selected,
        derive_debug = True,
        unavailable_image_digest = unavailable.image_digest,
    )

def php_matrix_targets(visibility = None):
    """Creates one provider-preserving target for every matrix header SDK."""
    visibility = visibility or ["//visibility:public"]
    result = []
    for name in sorted(PHP_MATRIX.keys()):
        record = PHP_MATRIX[name]
        if not record.supported:
            fail("whole-profile unsupported records must not be emitted: %s" % name)
        key = (_image_family(record), record.minor, record.arch, record.profile)
        implementation = "_%s_sdk" % name
        selected = _selected_import(key)
        if selected:
            _validate_import(record, selected)
            _snapshot(implementation, record, selected)
        elif key in PHP_OCI_UNAVAILABLE:
            _derived_debug_fallback(implementation, record, PHP_OCI_UNAVAILABLE[key])
        else:
            fail("PHP matrix record %s has no locked OCI SDK or explicit fallback" % name)
        configured_php_target(
            name = name,
            actual = ":" + implementation,
            matrix_platform = _platform(record),
            visibility = visibility,
        )
        result.append(":" + name)
    return result
