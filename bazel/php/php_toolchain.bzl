"""PHP SDK provider and rules used by extension targets."""

PhpToolchainInfo = provider(
    doc = "Exact PHP SDK/runtime ABI exposed to extension build actions.",
    fields = {
        "api": "Numeric PHP module API, for example 20220829.",
        "asan": "Whether this SDK and runtime are ASan-instrumented.",
        "bundled_extensions": "Bundled extensions linked into or shipped with PHP.",
        "debug": "Whether PHP was built with --enable-debug.",
        "generated_headers": "depset of generated configuration headers.",
        "include_paths": "Ordered include roots within the generated SDK.",
        "nts": "Whether the ABI is non-thread-safe.",
        "runtime_artifacts": "depset containing CLI/SAPI libraries and data.",
        "sapis": "Declared SAPI names available in runtime_artifacts.",
        "shared_extensions": "Bundled extensions configured as shared objects.",
        "target_arch": "amd64 or arm64.",
        "target_libc": "glibc or musl.",
        "version": "Exact PHP patch version.",
        "zts": "Whether the ABI is thread-safe.",
    },
)

def _php_sdk_impl(ctx):
    if ctx.attr.nts == ctx.attr.zts:
        fail("exactly one of nts and zts must be true")
    if ctx.attr.asan and ctx.attr.target_libc != "glibc":
        fail("ASan PHP SDKs are supported only for glibc targets")

    generated_headers = depset(ctx.files.generated_headers)
    runtime_artifacts = depset(ctx.files.runtime_artifacts)
    info = PhpToolchainInfo(
        api = ctx.attr.api,
        asan = ctx.attr.asan,
        bundled_extensions = tuple(ctx.attr.bundled_extensions),
        debug = ctx.attr.debug,
        generated_headers = generated_headers,
        include_paths = tuple(ctx.attr.include_paths),
        nts = ctx.attr.nts,
        runtime_artifacts = runtime_artifacts,
        sapis = tuple(ctx.attr.sapis),
        shared_extensions = tuple(ctx.attr.shared_extensions),
        target_arch = ctx.attr.target_arch,
        target_libc = ctx.attr.target_libc,
        version = ctx.attr.version,
        zts = ctx.attr.zts,
    )
    return [
        DefaultInfo(files = depset(transitive = [generated_headers, runtime_artifacts])),
        info,
        platform_common.ToolchainInfo(php = info),
    ]

php_sdk = rule(
    implementation = _php_sdk_impl,
    attrs = {
        "api": attr.int(mandatory = True),
        "asan": attr.bool(default = False),
        "bundled_extensions": attr.string_list(),
        "debug": attr.bool(default = False),
        "generated_headers": attr.label_list(allow_files = True, mandatory = True),
        "include_paths": attr.string_list(mandatory = True),
        "nts": attr.bool(default = True),
        "runtime_artifacts": attr.label_list(allow_files = True, mandatory = True),
        "sapis": attr.string_list(),
        "shared_extensions": attr.string_list(),
        "target_arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "target_libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "version": attr.string(mandatory = True),
        "zts": attr.bool(default = False),
    },
    doc = "Declares one immutable PHP ABI SDK and its matching runtime.",
)

def _php_sdk_manifest_impl(ctx):
    sdk = ctx.attr.sdk[PhpToolchainInfo]
    content = "{" + \
              "\"api\":%d," % sdk.api + \
              "\"asan\":%s," % str(sdk.asan).lower() + \
              "\"debug\":%s," % str(sdk.debug).lower() + \
              "\"nts\":%s," % str(sdk.nts).lower() + \
              "\"sapis\":%s," % _json_array(sdk.sapis) + \
              "\"shared_extensions\":%s," % _json_array(sdk.shared_extensions) + \
              "\"target_arch\":\"%s\"," % sdk.target_arch + \
              "\"target_libc\":\"%s\"," % sdk.target_libc + \
              "\"version\":\"%s\"," % sdk.version + \
              "\"zts\":%s" % str(sdk.zts).lower() + \
              "}\n"
    ctx.actions.write(ctx.outputs.out, content)
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

php_sdk_manifest = rule(
    implementation = _php_sdk_manifest_impl,
    attrs = {
        "sdk": attr.label(mandatory = True, providers = [PhpToolchainInfo]),
    },
    outputs = {"out": "%{name}.json"},
    doc = "Emits stable ABI metadata without embedding checkout or CI state.",
)

def _json_array(values):
    return "[%s]" % ",".join(["\"%s\"" % value for value in values])
