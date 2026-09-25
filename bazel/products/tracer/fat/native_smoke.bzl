"""Native PHP load smoke for a complete monolithic tracer product."""

load("//bazel/products/tracer:defs.bzl", "TracerCInfo")

def _ddtrace_native_smoke_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    host = ctx.toolchains["//bazel/generators:php_generator_type"].host_php
    tracer = ctx.attr.tracer[TracerCInfo]
    if host.arch != tracer.arch:
        fail("native ddtrace smoke execution architecture mismatch: PHP=%s tracer=%s" % (host.arch, tracer.arch))
    if tracer.libc != "glibc":
        fail("the current locked execution PHP smoke supports glibc products only")

    library_roots = {host.lib_root.dirname: None, tracer.curl.library.dirname: None}
    for library in tracer.curl.runtime.to_list():
        library_roots[library.dirname] = None
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._runner.path,
            host.loader.path,
            ":".join(sorted(library_roots.keys())),
            host.php.path,
            ctx.file.extension.path,
            host.version,
            ctx.file.version.path,
            marker.path,
            ctx.file._sidecar_runner.path,
            ctx.executable._sidecar_ping_client.path,
        ],
        inputs = depset(
            [ctx.file._runner, ctx.file._sidecar_runner, ctx.file.extension, ctx.file.version, tracer.curl.library, ctx.executable._sidecar_ping_client],
            transitive = [
                host.files,
                tracer.curl.runtime,
                foreign.files,
                ctx.attr._sidecar_ping_client[DefaultInfo].default_runfiles.files,
            ],
        ),
        outputs = [marker],
        env = dict(foreign.env, **{
            "DD_APPSEC_ENABLED": "false",
            "DD_PROFILING_ENABLED": "false",
            "DD_TRACE_ENABLED": "false",
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "DdtraceNativePhpSmoke",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

ddtrace_native_smoke = rule(
    implementation = _ddtrace_native_smoke_impl,
    attrs = {
        "extension": attr.label(allow_single_file = True, mandatory = True),
        "tracer": attr.label(mandatory = True, providers = [TracerCInfo]),
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer/fat:native-smoke.sh",
        ),
        "_sidecar_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer/fat:sidecar-direct-smoke.py",
        ),
        "_sidecar_ping_client": attr.label(
            cfg = "target",
            default = "//:rust_sidecar_ping_client",
            executable = True,
        ),
    },
    toolchains = [
        "//bazel/generators:php_generator_type",
        "//bazel/toolchains:hermetic_tools_type",
    ],
)
