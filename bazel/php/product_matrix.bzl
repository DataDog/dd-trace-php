"""Normalized product rows derived exclusively from ``PHP_MATRIX``.

Product macros consume these rows instead of maintaining their own PHP-minor
or ABI-profile tables.  The maps below are derived from the current packaging
scripts: extension artifacts belong to one PHP ABI row, while loader and
sidecar artifacts belong to one platform row and therefore share a build key.
"""

load(":matrix.bzl", "PHP_MATRIX")

_PRODUCT_NAMES = ["appsec", "loader", "profiler", "sidecar", "tracer"]

def _abi_flag(record, flag):
    return flag in record.configure_args

def _matrix_platform(record):
    return ("asan-" if record.sanitizer == "asan" else "") + "linux-%s-%s" % (
        record.arch,
        record.libc,
    )

def _package_os(record):
    return "linux-gnu" if record.libc == "glibc" else "linux-musl"

def _ci_arch(record):
    return "x86_64" if record.arch == "amd64" else "aarch64"

def _profile_suffix(record):
    if _abi_flag(record, "--enable-debug"):
        return "-debug-zts" if _is_zts(record) else "-debug"
    return "-zts" if _is_zts(record) else ""

def _is_zts(record):
    return _abi_flag(record, "--enable-zts") or _abi_flag(record, "--enable-maintainer-zts")

def _platform_key(record):
    return "%s_%s_%s" % (record.arch, record.libc, record.sanitizer)

def _product_build_keys(record):
    """Returns identities product macros use to deduplicate product builds."""
    platform_key = _platform_key(record)
    return {
        "appsec": record.name,
        "loader": "loader_" + platform_key,
        "profiler": record.name,
        "sidecar": "sidecar_" + platform_key,
        "tracer": record.name,
    }

def _tracer_variant(record):
    base = "%s_%s" % (record.arch, record.libc)
    if record.runtime_profile == "release":
        return base + "_release"
    if record.shared_build:
        return base + "_shared"
    if record.runtime_profile == "alpine-legacy":
        return base + "_legacy"
    return base

def _tracer_fat_label(record):
    """Returns a live fat-tracer label only where its macro emits one."""
    if record.sanitizer == "asan":
        return ()
    profile = record.profile.replace("-", "_")
    normal = record.runtime_profile in ("bookworm", "alpine") and not record.shared_build
    if profile == "nts":
        suffix = ""
    elif profile in ("zts", "debug"):
        suffix = "_profile_" + profile
    elif normal and record.runtime_profile == "bookworm" and profile == "debug_zts" and record.minor in ("7.0", "7.1", "7.2", "7.3"):
        suffix = "_profile_debug_zts"
    elif record.runtime_profile == "alpine-legacy" and profile == "debug_zts":
        suffix = "_profile_debug_zts"
    else:
        return ()
    minor = record.minor.replace(".", "")
    return ("//bazel/products/tracer:ddtrace_fat_%s_php%s%s" % (
        _tracer_variant(record),
        minor,
        suffix,
    ),)

def _product_labels(record):
    """Current labels only; product owners fill the empty future entries."""
    labels = _empty_artifacts()
    labels["tracer"] = _tracer_fat_label(record)
    if record.sanitizer == "none":
        labels["loader"] = (
            "//bazel/products/loader:loader_stage_%s_%s" % (
                record.arch,
                record.libc,
            ),
        )
    return labels

def _empty_artifacts():
    return {
        "appsec": (),
        "loader": (),
        "profiler": (),
        "sidecar": (),
        "tracer": (),
    }

def _ci_build_artifacts(record):
    """Legacy CI input locations, retained as product-output provenance."""
    artifacts = _empty_artifacts()
    architecture = _ci_arch(record)
    suffix = _profile_suffix(record)
    alpine = "-alpine" if record.libc == "musl" else ""
    artifacts["tracer"] = (
        "extensions_%s/ddtrace-%s%s%s.so" % (architecture, record.api, alpine, suffix),
    )
    artifacts["appsec"] = (
        "appsec_%s/ddappsec-%s%s%s.so" % (architecture, record.api, alpine, suffix),
    )
    if record.products["profiler"].supported:
        profiler_target = "unknown-linux-gnu" if record.libc == "glibc" else "alpine-linux-musl"
        artifacts["profiler"] = (
            "datadog-profiling/%s-%s/lib/php/%s/datadog-profiling%s.so" % (
                architecture,
                profiler_target,
                record.api,
                suffix,
            ),
        )
    # These platform products are intentionally repeated in this descriptive
    # map.  `product_build_keys` makes one target per platform, not per PHP.
    artifacts["loader"] = ("%s/loader/dd_library_loader.so" % _package_os(record),)
    artifacts["sidecar"] = (
        "libdatadog_php_%s%s.so" % (architecture, alpine),
        "libdatadog_php_%s%s.a" % (architecture, alpine),
    )
    return artifacts

def _build_artifact_roles(record):
    """Maps stable semantic roles to canonical product build identities."""
    platform_key = _platform_key(record)
    roles = {
        "appsec": {
            "binary": "appsec/extension/%s" % record.name,
            "debug": "appsec/extension/%s/debug" % record.name,
        },
        "loader": {
            "binary": "loader/%s" % platform_key,
            "debug": "loader/%s/debug" % platform_key,
        },
        "profiler": {},
        "sidecar": {
            "binary": "sidecar/%s/libdatadog_php.so" % platform_key,
            "debug": "sidecar/%s/libdatadog_php.so.debug" % platform_key,
            "archive": "common/%s/libdatadog_php.a" % platform_key,
        },
        "tracer": {
            "fat_binary": "tracer/fat/%s" % record.name,
            "fat_debug": "tracer/fat/%s/debug" % record.name,
            "slim_binary": "tracer/slim/%s" % record.name,
            "slim_debug": "tracer/slim/%s/debug" % record.name,
            "archive": "tracer/static/%s" % record.name,
        },
    }
    if record.products["profiler"].supported:
        roles["profiler"] = {
            "binary": "profiler/%s" % record.name,
            "debug": "profiler/%s/debug" % record.name,
        }
    return roles

def _artifact_identities(role_maps):
    return {product: tuple(role_maps[product].values()) for product in _PRODUCT_NAMES}

def _build_artifacts(record):
    """Stable expected identities, derived from explicit semantic roles."""
    return _artifact_identities(_build_artifact_roles(record))

def _validation_bundle_artifact_roles(record):
    """Per-ABI validation-bundle destinations, including every ASan row.

    This normalized layout is separate from the release SSI layout below.
    Several rows can reference a platform-scoped artifact through the same
    `product_build_keys` value; that never requests duplicate native builds.
    """
    root = "validation/%s" % record.name
    roles = {
        "appsec": {
            "binary": root + "/appsec/ddappsec.so",
            "debug": root + "/appsec/ddappsec.so.debug",
        },
        "loader": {
            "binary": root + "/loader/dd_library_loader.so",
            "debug": root + "/loader/dd_library_loader.so.debug",
        },
        "profiler": {},
        "sidecar": {
            "binary": root + "/loader/libdatadog_php.so",
            "debug": root + "/loader/libdatadog_php.so.debug",
            "archive": root + "/common/libdatadog_php.a",
        },
        "tracer": {
            "fat_binary": root + "/trace/ddtrace-fat.so",
            "fat_debug": root + "/trace/ddtrace-fat.so.debug",
            "slim_binary": root + "/trace/ddtrace-slim.so",
            "slim_debug": root + "/trace/ddtrace-slim.so.debug",
            "archive": root + "/trace/ddtrace.a",
        },
    }
    if record.products["profiler"].supported:
        roles["profiler"] = {
            "binary": root + "/profiling/datadog-profiling.so",
            "debug": root + "/profiling/datadog-profiling.so.debug",
        }
    return roles

def _validation_bundle_artifacts(record):
    return _artifact_identities(_validation_bundle_artifact_roles(record))

def _appsec_helper_relationship(record):
    """Describes the Rust helper embedded in the platform sidecar library.

    `ddappsec_helper` is a Cargo library called by `sidecar/src/lib.rs`; it
    is not a standalone AppSec executable and has no independent shipping
    artifact.  Its behavior must instead be covered by the sidecar's native
    start, message, and shutdown checks.
    """
    platform_key = _platform_key(record)
    return {
        "artifact_identity": "sidecar/%s/libdatadog_php.so" % platform_key,
        "artifact_product": "sidecar",
        "build_key": "sidecar_" + platform_key,
        "kind": "embedded_rust_library",
        "native_checks": ("start", "message", "shutdown"),
    }

def _asan_runtime_scope_key(record):
    return "asan_runtime_" + _platform_key(record)

def _asan_runtime_build_artifact_roles(record):
    """Provider-backed runtime identities required by ASan product builds."""
    if record.sanitizer != "asan":
        return {}
    root = "runtime/asan/%s" % _platform_key(record)
    return {
        "shared": root + "/shared",
        "static": root + "/static",
        "cxx_static": root + "/cxx_static",
        "preinit_static": root + "/preinit_static",
    }

def _asan_runtime_build_artifacts(record):
    return tuple(_asan_runtime_build_artifact_roles(record).values())

def _asan_runtime_validation_artifact_roles(record):
    """The shared ASan DSO required in each native validation closure."""
    if record.sanitizer != "asan":
        return {}
    architecture = "x86_64" if record.arch == "amd64" else "aarch64"
    return {
        "shared": "validation/%s/runtime/libclang_rt.asan-%s.so" % (record.name, architecture),
    }

def _asan_runtime_validation_artifacts(record):
    return tuple(_asan_runtime_validation_artifact_roles(record).values())

def _package_artifacts(record):
    """Shipping-SSI destinations; its source script intentionally omits ASan."""
    artifacts = _empty_artifacts()
    if record.sanitizer == "asan":
        return artifacts

    os_path = _package_os(record)
    suffix = _profile_suffix(record)
    artifacts["tracer"] = (
        "%s/trace/ext/%s/ddtrace%s.so" % (os_path, record.api, suffix),
    )
    artifacts["appsec"] = (
        "%s/appsec/ext/%s/ddappsec%s.so" % (os_path, record.api, suffix),
    )
    if record.products["profiler"].supported:
        artifacts["profiler"] = (
            "%s/profiling/ext/%s/datadog-profiling%s.so" % (
                os_path,
                record.api,
                suffix,
            ),
        )
    artifacts["loader"] = (
        "%s/loader/dd_library_loader.so" % os_path,
        "%s/loader/dd_library_loader.so.debug" % os_path,
    )
    artifacts["sidecar"] = (
        "%s/loader/libdatadog_php.so" % os_path,
        "%s/loader/libdatadog_php.so.debug" % os_path,
    )
    return artifacts

def _shipping_ssi_products(record):
    products = {}
    for name in _PRODUCT_NAMES:
        status = record.products[name]
        products[name] = {
            "supported": status.supported and record.sanitizer != "asan",
            "reason": "tooling/bin/generate-ssi-package.sh exits 0 when DDTRACE_MAKE_PACKAGES_ASAN is set" if record.sanitizer == "asan" else status.reason,
        }
    return products

def _products(record):
    """Copies canonical support data into the public dict-shaped interface."""
    products = {}
    for name in _PRODUCT_NAMES:
        status = record.products[name]
        products[name] = {
            "supported": status.supported,
            "reason": status.reason,
        }
    return products

def _product_scopes():
    return {
        "appsec": "php_abi",
        "loader": "platform",
        "profiler": "php_abi",
        "sidecar": "platform",
        "tracer": "php_abi",
    }

def _row(record):
    zts = _is_zts(record)
    return struct(
        name = record.name,
        sdk_label = "//bazel/php:%s" % record.name,
        matrix_platform = _matrix_platform(record),
        sanitizer = record.sanitizer,
        sdk_family = record.runtime_profile,
        source_key = record.source_key,
        target_arch = record.arch,
        target_libc = record.libc,
        target_triple = record.target_triple,
        php_minor = record.minor,
        php_api = record.api,
        abi_profile = record.profile,
        debug = _abi_flag(record, "--enable-debug"),
        zts = zts,
        nts = not zts,
        shared_build = record.shared_build,
        products = _products(record),
        product_scopes = _product_scopes(),
        product_build_keys = _product_build_keys(record),
        product_labels = _product_labels(record),
        shipping_ssi_products = _shipping_ssi_products(record),
        build_artifact_roles = _build_artifact_roles(record),
        build_artifacts = _build_artifacts(record),
        ci_build_artifacts = _ci_build_artifacts(record),
        package_artifacts = _package_artifacts(record),
        validation_bundle_artifact_roles = _validation_bundle_artifact_roles(record),
        validation_bundle_artifacts = _validation_bundle_artifacts(record),
        asan_runtime_scope_key = _asan_runtime_scope_key(record),
        asan_runtime_build_artifact_roles = _asan_runtime_build_artifact_roles(record),
        asan_runtime_build_artifacts = _asan_runtime_build_artifacts(record),
        asan_runtime_validation_artifact_roles = _asan_runtime_validation_artifact_roles(record),
        asan_runtime_validation_artifacts = _asan_runtime_validation_artifacts(record),
        appsec_helper_relationship = _appsec_helper_relationship(record),
    )

def _validate(rows):
    if len(rows) != len(PHP_MATRIX):
        fail("product matrix row count does not cover PHP_MATRIX")
    names = {}
    for row in rows:
        if row.name in names:
            fail("duplicate product matrix row: %s" % row.name)
        names[row.name] = True
        if row.sdk_label != "//bazel/php:%s" % row.name:
            fail("product matrix row has a noncanonical SDK label: %s" % row.name)
        if sorted(row.products.keys()) != _PRODUCT_NAMES:
            fail("product matrix row has incomplete product coverage: %s" % row.name)
        if sorted(row.build_artifacts.keys()) != _PRODUCT_NAMES or \
                sorted(row.build_artifact_roles.keys()) != _PRODUCT_NAMES or \
                sorted(row.ci_build_artifacts.keys()) != _PRODUCT_NAMES or \
                sorted(row.package_artifacts.keys()) != _PRODUCT_NAMES or \
                sorted(row.validation_bundle_artifacts.keys()) != _PRODUCT_NAMES or \
                sorted(row.validation_bundle_artifact_roles.keys()) != _PRODUCT_NAMES:
            fail("product matrix row has incomplete artifact coverage: %s" % row.name)
        if sorted(row.product_build_keys.keys()) != _PRODUCT_NAMES or \
                sorted(row.product_labels.keys()) != _PRODUCT_NAMES or \
                sorted(row.shipping_ssi_products.keys()) != _PRODUCT_NAMES or \
                sorted(row.product_scopes.keys()) != _PRODUCT_NAMES:
            fail("product matrix row has incomplete product-key coverage: %s" % row.name)
        if row.nts == row.zts:
            fail("product matrix row must be exactly one of NTS or ZTS: %s" % row.name)
        for product in _PRODUCT_NAMES:
            if tuple(row.build_artifact_roles[product].values()) != row.build_artifacts[product]:
                fail("build artifacts must be derived from roles: %s/%s" % (row.name, product))
            if tuple(row.validation_bundle_artifact_roles[product].values()) != row.validation_bundle_artifacts[product]:
                fail("validation artifacts must be derived from roles: %s/%s" % (row.name, product))
            if row.products[product]["supported"] and not row.build_artifacts[product]:
                fail("supported product has no expected build artifact: %s/%s" % (row.name, product))
            if row.products[product]["supported"] and not row.validation_bundle_artifacts[product]:
                fail("supported product has no validation-bundle artifact: %s/%s" % (row.name, product))
        if row.sanitizer == "asan":
            if (not row.asan_runtime_build_artifacts or not row.asan_runtime_validation_artifacts):
                fail("ASan row has no declared runtime closure: %s" % row.name)
            if tuple(row.asan_runtime_build_artifact_roles.values()) != row.asan_runtime_build_artifacts or \
                    tuple(row.asan_runtime_validation_artifact_roles.values()) != row.asan_runtime_validation_artifacts:
                fail("ASan runtime artifacts must be derived from roles: %s" % row.name)
        helper = row.appsec_helper_relationship
        if (helper["artifact_product"] != "sidecar" or
                not helper["artifact_identity"] or
                sorted(helper["native_checks"]) != ["message", "shutdown", "start"]):
            fail("AppSec helper relationship is incomplete: %s" % row.name)
    if sorted(names.keys()) != sorted(PHP_MATRIX.keys()):
        fail("product matrix row names do not exactly match PHP_MATRIX")

def product_matrix_rows():
    """Returns every PHP ABI row in canonical-name order."""
    rows = tuple([_row(PHP_MATRIX[name]) for name in sorted(PHP_MATRIX.keys())])
    _validate(rows)
    return rows

def product_matrix_summary():
    """Returns derived coverage counts for aggregate product assertions."""
    rows = product_matrix_rows()
    asan_rows = len([row for row in rows if row.sanitizer == "asan"])
    return struct(rows = len(rows), asan_rows = asan_rows)

def _json_row(row):
    return {
        "abi_profile": row.abi_profile,
        "asan_runtime_build_artifact_roles": row.asan_runtime_build_artifact_roles,
        "asan_runtime_build_artifacts": row.asan_runtime_build_artifacts,
        "asan_runtime_scope_key": row.asan_runtime_scope_key,
        "asan_runtime_validation_artifact_roles": row.asan_runtime_validation_artifact_roles,
        "asan_runtime_validation_artifacts": row.asan_runtime_validation_artifacts,
        "appsec_helper_relationship": row.appsec_helper_relationship,
        "build_artifact_roles": row.build_artifact_roles,
        "build_artifacts": row.build_artifacts,
        "ci_build_artifacts": row.ci_build_artifacts,
        "debug": row.debug,
        "matrix_platform": row.matrix_platform,
        "name": row.name,
        "nts": row.nts,
        "package_artifacts": row.package_artifacts,
        "php_api": row.php_api,
        "php_minor": row.php_minor,
        "product_build_keys": row.product_build_keys,
        "product_labels": row.product_labels,
        "product_scopes": row.product_scopes,
        "products": row.products,
        "sanitizer": row.sanitizer,
        "sdk_family": row.sdk_family,
        "sdk_label": row.sdk_label,
        "shared_build": row.shared_build,
        "shipping_ssi_products": row.shipping_ssi_products,
        "source_key": row.source_key,
        "target_arch": row.target_arch,
        "target_libc": row.target_libc,
        "target_triple": row.target_triple,
        "validation_bundle_artifacts": row.validation_bundle_artifacts,
        "validation_bundle_artifact_roles": row.validation_bundle_artifact_roles,
        "zts": row.zts,
    }

def _product_matrix_manifest_impl(ctx):
    rows = product_matrix_rows()
    ctx.actions.write(
        output = ctx.outputs.out,
        content = json.encode({
            "rows": [_json_row(row) for row in rows],
            "schema_version": 1,
            "summary": {
                "asan_rows": len([row for row in rows if row.sanitizer == "asan"]),
                "rows": len(rows),
            },
        }) + "\n",
    )
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

product_matrix_manifest = rule(
    implementation = _product_matrix_manifest_impl,
    outputs = {"out": "%{name}.json"},
)
