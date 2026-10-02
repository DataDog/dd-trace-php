"""PHP SDK provider backed by checksum-locked OCI profile snapshots."""

load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")

PhpToolchainInfo = provider(
    doc = "Complete PHP header SDK and ABI metadata for native extension actions.",
    fields = {
        "api": "Numeric PHP module API read from the imported headers.",
        "asan": "Whether the matching image profile is ASan-instrumented.",
        "bundled_extensions": "Observed bundled extension inventory; empty for header-only imports.",
        "cc_info": "CcInfo for the complete PHP header tree.",
        "configuration_name": "Stable matrix configuration name.",
        "config": "depset containing imported configuration files, when retained.",
        "debug": "Whether ZEND_DEBUG is enabled in php_config.h.",
        "declared_source_version": "Historical CI source pin retained as provenance.",
        "extensions": "depset containing imported shared extensions, when retained.",
        "generation_method": "oci-profile-snapshot or an explicitly validated header transform.",
        "headers": "depset containing the complete installed PHP header tree.",
        "host_php": "Optional execution-platform PHP generator; None for header SDKs.",
        "image_family": "OCI image family supplying this SDK.",
        "libs": "depset containing imported target libraries, when retained.",
        "minor": "Requested PHP major.minor line.",
        "nts": "Whether the ABI is non-thread-safe.",
        "observed_metadata": "Validated metadata embedded in the imported SDK.",
        "php_cli": "Optional target PHP CLI; None for header SDKs.",
        "php_config": "Relocatable generated php-config query for the header SDK.",
        "php_config_queries": "Queries implemented by the generated header-only php-config.",
        "php_symbol_reference": "Target PHP ELF used only to identify PHP-owned undefined symbols; it is not an execution runtime.",
        "phpize": "Optional relocatable phpize; None for header SDKs.",
        "provenance": "OCI descriptor, layer, and historical source provenance inputs.",
        "runtime": "depset containing retained runtime files; normally empty.",
        "runtime_profile": "ABI profile (nts, zts, debug, or sanitizer variant).",
        "sapis": "Observed SAPI inventory; empty for header-only imports.",
        "sdk": "Declared directory containing the relocatable SDK output.",
        "shared_extensions": "Observed shared extension inventory; empty for header-only imports.",
        "shared_build": "Whether this is the CI shared-extension compilation profile.",
        "sdk_source_family": "OCI family containing the imported base header tree.",
        "source_image_digest": "Immutable OCI index digest supplying this SDK.",
        "target_arch": "amd64 or arm64.",
        "target_libc": "glibc or musl.",
        "target_triple": "Canonical LLVM target triple.",
        "version": "Actual PHP patch version validated from imported headers.",
        "zts": "Whether the ABI is thread-safe.",
        "zend_extension_api": "Numeric Zend extension API read from the imported headers.",
        "zend_module_api": "Numeric Zend module API read from the imported headers.",
    },
)

def _quote_json(value):
    return "\"%s\"" % value.replace("\\", "\\\\").replace("\"", "\\\"")

def _header_map(files):
    rows = []
    marker = "/normalized/sdk/"
    for header in files:
        path = "/" + header.short_path
        parts = path.split(marker)
        if len(parts) != 2 or not parts[1].startswith("include/php/"):
            fail("OCI PHP header is outside normalized/sdk/include/php: %s" % header.short_path)
        rows.append("%s\t%s" % (header.path, parts[1]))
    return "\n".join(sorted(rows)) + "\n"

def _php_sdk_snapshot_impl(ctx):
    if ctx.attr.nts == ctx.attr.zts:
        fail("exactly one of nts and zts must be true")
    if ctx.attr.asan and ctx.attr.target_libc != "glibc":
        fail("ASan PHP SDKs are supported only on glibc")
    if not ctx.attr.version.startswith(ctx.attr.minor + "."):
        fail("actual SDK version %s is outside PHP %s" % (ctx.attr.version, ctx.attr.minor))

    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    imported_headers = ctx.attr.headers[DefaultInfo].files
    libs = ctx.attr.libs[DefaultInfo].files
    extensions = ctx.attr.extensions[DefaultInfo].files
    config = ctx.attr.config[DefaultInfo].files
    runtime = ctx.attr.runtime[DefaultInfo].files
    if len(ctx.files.provenance) != 1:
        fail("PHP OCI SDK expects exactly one provenance file")
    header_map = ctx.actions.declare_file(ctx.label.name + ".headers")
    ctx.actions.write(header_map, _header_map(imported_headers.to_list()))
    sdk = ctx.actions.declare_directory(ctx.label.name + ".sdk")
    effective_observed = ctx.actions.declare_file(ctx.label.name + ".observed.json")
    php_config = ctx.actions.declare_file(ctx.label.name + ".tools/php-config")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._materializer.path,
            header_map.path,
            sdk.path,
            ctx.file.observed.path,
            ctx.file.lock.path,
            ctx.files.provenance[0].path,
            "1" if ctx.attr.derive_debug else "0",
            ctx.attr.unavailable_image_digest,
            effective_observed.path,
            ctx.attr.version,
            str(ctx.attr.api),
            str(ctx.attr.zend_module_api),
            str(ctx.attr.zend_extension_api),
            ctx.attr.target_arch,
            str(ctx.attr.base_debug).lower(),
            str(ctx.attr.zts).lower(),
            str(ctx.attr.asan).lower(),
            ctx.attr.sdk_source_family,
            ctx.attr.base_profile,
            ctx.attr.source_image_digest,
            ctx.attr.target_libc,
            ctx.attr.target_triple,
            php_config.path,
            ctx.file._metadata_validator.path,
        ],
        inputs = depset(
            [header_map, ctx.file._materializer, ctx.file._metadata_validator, ctx.file.observed, ctx.file.lock, ctx.file.sdk_root] + ctx.files.provenance,
            transitive = [imported_headers, foreign.files],
        ),
        outputs = [sdk, effective_observed, php_config],
        env = dict(foreign.env, **{
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "SOURCE_DATE_EPOCH": "0",
            "TZ": "UTC",
        }),
        mnemonic = "PhpSdkMaterialize",
        progress_message = "Materializing PHP %s %s header SDK" % (ctx.attr.version, ctx.attr.runtime_profile),
        use_default_shell_env = False,
    )
    headers = depset([sdk])
    include_root = sdk.path + "/include/php"
    compilation_context = cc_common.create_compilation_context(
        headers = headers,
        includes = depset([
            include_root,
            include_root + "/main",
            include_root + "/TSRM",
            include_root + "/Zend",
            include_root + "/ext",
            include_root + "/ext/date/lib",
        ]),
    )
    cc_info = CcInfo(compilation_context = compilation_context)

    metadata = ctx.actions.declare_file(ctx.label.name + ".sdk.json")
    ctx.actions.write(
        metadata,
        "{" +
        "\"api\":%d," % ctx.attr.api +
        "\"asan\":%s," % str(ctx.attr.asan).lower() +
        "\"configuration_name\":%s," % _quote_json(ctx.attr.configuration_name) +
        "\"debug\":%s," % str(ctx.attr.debug).lower() +
        "\"declared_source_version\":%s," % _quote_json(ctx.attr.declared_source_version) +
        "\"generation_method\":%s," % _quote_json("oci-debug-header-transform" if ctx.attr.derive_debug else "oci-profile-snapshot") +
        "\"image_family\":%s," % _quote_json(ctx.attr.image_family) +
        "\"minor\":%s," % _quote_json(ctx.attr.minor) +
        "\"runtime_profile\":%s," % _quote_json(ctx.attr.runtime_profile) +
        "\"sdk_source_family\":%s," % _quote_json(ctx.attr.sdk_source_family) +
        "\"shared_build\":%s," % str(ctx.attr.shared_build).lower() +
        "\"source_image_digest\":%s," % _quote_json(ctx.attr.source_image_digest) +
        "\"target_arch\":%s," % _quote_json(ctx.attr.target_arch) +
        "\"target_libc\":%s," % _quote_json(ctx.attr.target_libc) +
        "\"target_triple\":%s," % _quote_json(ctx.attr.target_triple) +
        "\"unavailable_image_digest\":%s," % _quote_json(ctx.attr.unavailable_image_digest) +
        "\"version\":%s," % _quote_json(ctx.attr.version) +
        "\"zend_extension_api\":%d," % ctx.attr.zend_extension_api +
        "\"zend_module_api\":%d," % ctx.attr.zend_module_api +
        "\"zts\":%s" % str(ctx.attr.zts).lower() +
        "}\n",
    )

    provenance = depset(ctx.files.provenance + ctx.files.observed + ctx.files.lock + [effective_observed])
    info = PhpToolchainInfo(
        api = ctx.attr.api,
        asan = ctx.attr.asan,
        bundled_extensions = (),
        cc_info = cc_info,
        configuration_name = ctx.attr.configuration_name,
        config = config,
        debug = ctx.attr.debug,
        declared_source_version = ctx.attr.declared_source_version,
        extensions = extensions,
        generation_method = "oci-debug-header-transform" if ctx.attr.derive_debug else "oci-profile-snapshot",
        headers = headers,
        host_php = None,
        image_family = ctx.attr.image_family,
        libs = libs,
        minor = ctx.attr.minor,
        nts = ctx.attr.nts,
        observed_metadata = effective_observed,
        php_cli = None,
        php_config = php_config,
        php_config_queries = ("--include-dir", "--includes", "--phpapi", "--prefix", "--vernum", "--version"),
        php_symbol_reference = ctx.file.php_symbol_reference,
        phpize = None,
        provenance = provenance,
        runtime = runtime,
        runtime_profile = ctx.attr.runtime_profile,
        sapis = (),
        sdk = sdk,
        sdk_source_family = ctx.attr.sdk_source_family,
        shared_extensions = (),
        shared_build = ctx.attr.shared_build,
        source_image_digest = ctx.attr.source_image_digest,
        target_arch = ctx.attr.target_arch,
        target_libc = ctx.attr.target_libc,
        target_triple = ctx.attr.target_triple,
        version = ctx.attr.version,
        zend_extension_api = ctx.attr.zend_extension_api,
        zend_module_api = ctx.attr.zend_module_api,
        zts = ctx.attr.zts,
    )
    files = depset(
        [metadata, sdk, effective_observed, php_config],
        transitive = [headers, libs, extensions, config, runtime, provenance],
    )
    return [DefaultInfo(files = files), info, cc_info, platform_common.ToolchainInfo(php = info)]

php_sdk_snapshot = rule(
    implementation = _php_sdk_snapshot_impl,
    attrs = {
        "api": attr.int(mandatory = True),
        "asan": attr.bool(),
        "base_debug": attr.bool(),
        "base_profile": attr.string(mandatory = True),
        "config": attr.label(allow_files = True, mandatory = True),
        "configuration_name": attr.string(mandatory = True),
        "debug": attr.bool(),
        "declared_source_version": attr.string(mandatory = True),
        "derive_debug": attr.bool(),
        "extensions": attr.label(allow_files = True, mandatory = True),
        "headers": attr.label(allow_files = True, mandatory = True),
        "image_family": attr.string(mandatory = True),
        "libs": attr.label(allow_files = True, mandatory = True),
        "lock": attr.label(allow_single_file = True, mandatory = True),
        "minor": attr.string(mandatory = True),
        "nts": attr.bool(default = True),
        "observed": attr.label(allow_single_file = True, mandatory = True),
        "php_symbol_reference": attr.label(allow_single_file = True, mandatory = True),
        "provenance": attr.label_list(allow_files = True, mandatory = True),
        "runtime": attr.label(allow_files = True, mandatory = True),
        "runtime_profile": attr.string(mandatory = True),
        "sdk_root": attr.label(allow_single_file = True, mandatory = True),
        "sdk_source_family": attr.string(mandatory = True),
        "shared_build": attr.bool(),
        "source_image_digest": attr.string(mandatory = True),
        "target_arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "target_libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "target_triple": attr.string(mandatory = True),
        "unavailable_image_digest": attr.string(),
        "version": attr.string(mandatory = True),
        "zts": attr.bool(),
        "zend_extension_api": attr.int(mandatory = True),
        "zend_module_api": attr.int(mandatory = True),
        "_materializer": attr.label(default = "//tools/bazel:materialize_php_sdk", allow_single_file = True),
        "_metadata_validator": attr.label(default = "//tools/bazel:validate_php_sdk_metadata", allow_single_file = True),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _php_sdk_manifest_impl(ctx):
    sdk = ctx.attr.sdk[PhpToolchainInfo]
    content = "{" + \
              "\"api\":%d," % sdk.api + \
              "\"asan\":%s," % str(sdk.asan).lower() + \
              "\"debug\":%s," % str(sdk.debug).lower() + \
              "\"image_family\":%s," % _quote_json(sdk.image_family) + \
              "\"minor\":%s," % _quote_json(sdk.minor) + \
              "\"runtime_profile\":%s," % _quote_json(sdk.runtime_profile) + \
              "\"target_arch\":%s," % _quote_json(sdk.target_arch) + \
              "\"target_libc\":%s," % _quote_json(sdk.target_libc) + \
              "\"target_triple\":%s," % _quote_json(sdk.target_triple) + \
              "\"version\":%s" % _quote_json(sdk.version) + "}\n"
    ctx.actions.write(ctx.outputs.out, content)
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

php_sdk_manifest = rule(
    implementation = _php_sdk_manifest_impl,
    attrs = {"sdk": attr.label(mandatory = True, providers = [PhpToolchainInfo])},
    outputs = {"out": "%{name}.json"},
)

def _php_sdk_inventory_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    records = []
    for dep in ctx.attr.sdks:
        sdk = dep[PhpToolchainInfo]
        records.append((sdk.configuration_name, sdk))
    records = sorted(records)
    if len(records) != 226:
        fail("PHP SDK inventory requires all 226 configurations, got %d" % len(records))
    if len({name: True for name, _ in records}) != len(records):
        fail("PHP SDK inventory contains duplicate configuration names")
    rows = []
    for name, sdk in records:
        rows.append("{" + ",".join([
            "\"name\":" + _quote_json(name),
            "\"actual_version\":" + _quote_json(sdk.version),
            "\"declared_source_version\":" + _quote_json(sdk.declared_source_version),
            "\"php_api\":%d" % sdk.api,
            "\"zend_module_api\":%d" % sdk.zend_module_api,
            "\"zend_extension_api\":%d" % sdk.zend_extension_api,
            "\"arch\":" + _quote_json(sdk.target_arch),
            "\"libc\":" + _quote_json(sdk.target_libc),
            "\"target_triple\":" + _quote_json(sdk.target_triple),
            "\"profile\":" + _quote_json(sdk.runtime_profile),
            "\"debug\":%s" % str(sdk.debug).lower(),
            "\"zts\":%s" % str(sdk.zts).lower(),
            "\"asan\":%s" % str(sdk.asan).lower(),
            "\"image_family\":" + _quote_json(sdk.image_family),
            "\"sdk_source_family\":" + _quote_json(sdk.sdk_source_family),
            "\"source_image_digest\":" + _quote_json(sdk.source_image_digest),
            "\"generation_method\":" + _quote_json(sdk.generation_method),
            "\"sdk_output\":" + _quote_json(sdk.sdk.path),
            "\"sdk_logical_output\":" + _quote_json(sdk.sdk.short_path),
        ]) + "}")
    spec = ctx.actions.declare_file(ctx.label.name + ".spec.json")
    ctx.actions.write(
        spec,
        "{\"schema_version\":1,\"records\":[" + ",".join(rows) + "]}\n",
    )
    arguments = [ctx.file._writer.path, spec.path, ctx.outputs.out.path]
    sdk_inputs = []
    for name, sdk in records:
        arguments.extend([name, sdk.sdk.path])
        sdk_inputs.append(sdk.headers)
    ctx.actions.run(
        executable = foreign.shell,
        arguments = arguments,
        inputs = depset(
            [spec, ctx.file._writer],
            transitive = sdk_inputs + [foreign.files],
        ),
        outputs = [ctx.outputs.out],
        env = dict(foreign.env, **{
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "SOURCE_DATE_EPOCH": "0",
            "TZ": "UTC",
        }),
        mnemonic = "PhpSdkInventory",
        progress_message = "Validating all 226 PHP SDK outputs",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

php_sdk_inventory = rule(
    implementation = _php_sdk_inventory_impl,
    attrs = {
        "sdks": attr.label_list(mandatory = True, providers = [PhpToolchainInfo]),
        "_writer": attr.label(default = "//tools/bazel:write_php_sdk_inventory", allow_single_file = True),
    },
    outputs = {"out": "%{name}.json"},
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)
