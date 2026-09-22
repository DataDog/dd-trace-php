"""Validated target curl SDK provider for native Datadog products."""

load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")

_SYSROOT_TYPE = "//bazel/toolchains:sysroot_type"

TargetCurlInfo = provider(
    doc = "Locked CI curl SDK and its target runtime closure.",
    fields = {
        "arch": "amd64 or arm64",
        "data": "non-ELF runtime data such as the certificate bundle",
        "libc": "target C library ABI (currently glibc only)",
        "library": "libcurl shared-library payload",
        "lock": "OCI layer lock metadata",
        "observed": "Validated curl SDK metadata",
        "runtime": "depset containing OpenSSL and zlib runtime DSOs",
        "target_triple": "normalized target triple",
        "version": "curl version",
    },
)

def _target_curl_sdk_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains[_SYSROOT_TYPE].sysroot
    if sysroot.libc != ctx.attr.libc or sysroot.target_triple != ctx.attr.target_triple:
        fail("curl SDK %s requires matching %s sysroot %s" % (ctx.attr.target_triple, ctx.attr.libc, sysroot.target_triple))
    marker = ctx.actions.declare_file(ctx.label.name + ".validated")
    args = ctx.actions.args()
    args.add(ctx.file._validator.path)
    args.add(foreign.objdump.path)
    args.add(ctx.file.library.path)
    args.add(ctx.file.header.path)
    args.add(ctx.attr.arch)
    args.add(ctx.attr.libc)
    args.add(ctx.attr.version)
    args.add(marker.path)
    args.add(sysroot.root.dirname)
    args.add(len(ctx.attr.expected_needed))
    args.add_all(sorted(ctx.attr.expected_needed))
    args.add_all(ctx.files.runtime)
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [args],
        inputs = depset(
            [ctx.file._validator, ctx.file.header, ctx.file.library, ctx.file.lock, ctx.file.observed] + ctx.files.data + ctx.files.runtime,
            transitive = [ctx.attr.all[DefaultInfo].files, foreign.files, foreign.compiler_files, sysroot.files],
        ),
        outputs = [marker],
        env = dict(foreign.env, **{
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "ValidateTargetCurlSdk",
        progress_message = "Validating curl %s %s-%s target SDK" % (ctx.attr.version, ctx.attr.arch, ctx.attr.libc),
        use_default_shell_env = False,
    )
    runtime = depset(ctx.files.runtime)
    data = depset(ctx.files.data)
    info = TargetCurlInfo(
        arch = ctx.attr.arch,
        data = data,
        libc = ctx.attr.libc,
        library = ctx.file.library,
        lock = ctx.file.lock,
        observed = ctx.file.observed,
        runtime = runtime,
        target_triple = ctx.attr.target_triple,
        version = ctx.attr.version,
    )
    return [
        DefaultInfo(files = depset([marker, ctx.file.library, ctx.file.lock, ctx.file.observed], transitive = [data, runtime])),
        OutputGroupInfo(_validation = depset([marker])),
        ctx.attr.cc[CcInfo],
        info,
    ]

target_curl_sdk = rule(
    implementation = _target_curl_sdk_impl,
    attrs = {
        "all": attr.label(mandatory = True),
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "cc": attr.label(mandatory = True, providers = [CcInfo]),
        "data": attr.label(allow_files = True, mandatory = True),
        "expected_needed": attr.string_list(mandatory = True),
        "header": attr.label(allow_single_file = True, mandatory = True),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "library": attr.label(allow_single_file = True, mandatory = True),
        "lock": attr.label(allow_single_file = True, mandatory = True),
        "observed": attr.label(allow_single_file = True, mandatory = True),
        "runtime": attr.label(allow_files = True, mandatory = True),
        "target_triple": attr.string(mandatory = True),
        "version": attr.string(mandatory = True),
        "_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/target_curl_oci:validate-curl-sdk.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type", _SYSROOT_TYPE],
)
