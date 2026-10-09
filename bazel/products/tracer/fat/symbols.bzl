"""Validation for the production fat-tracer export manifest."""

def _tracer_symbol_manifest_check_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")

    # This is config.m4's semantic concatenation order. Named attributes keep
    # formatters from sorting the source list and changing the public ABI.
    sources = [
        ctx.file.extension,
        ctx.file.extension_linux,
        ctx.file.rust,
        ctx.file.rust_unix,
        ctx.file.rust_linux,
    ]
    ctx.actions.run(
        executable = foreign.busybox,
        arguments = [
            "sh",
            ctx.file._runner.path,
            foreign.busybox.path,
            ctx.file.expected.path,
            marker.path,
        ] + [src.path for src in sources],
        inputs = depset(
            [ctx.file._runner, ctx.file.expected] + sources,
            transitive = [depset([foreign.busybox])],
        ),
        outputs = [marker],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "ValidateDdtraceFatSymbols",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

tracer_symbol_manifest_check = rule(
    implementation = _tracer_symbol_manifest_check_impl,
    attrs = {
        "extension": attr.label(allow_single_file = True, mandatory = True),
        "extension_linux": attr.label(allow_single_file = True, mandatory = True),
        "expected": attr.label(allow_single_file = True, mandatory = True),
        "rust": attr.label(allow_single_file = True, mandatory = True),
        "rust_linux": attr.label(allow_single_file = True, mandatory = True),
        "rust_unix": attr.label(allow_single_file = True, mandatory = True),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer/fat:check-symbol-manifest.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)
