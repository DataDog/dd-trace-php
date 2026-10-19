"""Remote execution architecture probe using only the pinned static shell."""

def _arch_probe_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    out = ctx.actions.declare_file(ctx.label.name + ".txt")
    ctx.actions.run(
        executable = foreign.busybox,
        arguments = ["sh", ctx.file._probe.path, out.path, ctx.attr.expected],
        inputs = depset([ctx.file._probe, foreign.busybox]),
        outputs = [out],
        env = {
            "HERMETIC_BUSYBOX": foreign.busybox.path,
            "LANG": "C.UTF-8",
            "LC_ALL": "C.UTF-8",
            "PATH": ":".join(foreign.path_entries),
        },
        mnemonic = "RemoteArchitectureProbe",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([out]))]

arch_probe = rule(
    implementation = _arch_probe_impl,
    attrs = {
        "expected": attr.string(mandatory = True),
        "_probe": attr.label(default = "//tools/bazel:arch_probe", allow_single_file = True),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)
