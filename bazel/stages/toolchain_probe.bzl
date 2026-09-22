"""Real execution/compiler launcher gate."""

def _toolchain_probe_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    out = ctx.actions.declare_file(ctx.label.name + ".txt")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [ctx.file._probe.path, out.path],
        inputs = depset([ctx.file._probe], transitive = [foreign.files, foreign.compiler_files]),
        outputs = [out],
        env = dict(foreign.env, **{
            "LANG": "C.UTF-8",
            "LC_ALL": "C.UTF-8",
            "PATH": ":".join(foreign.path_entries),
        }),
        mnemonic = "HermeticToolchainProbe",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([out]))]

toolchain_probe = rule(
    implementation = _toolchain_probe_impl,
    attrs = {
        "_probe": attr.label(default = "//tools/bazel:toolchain_probe", allow_single_file = True),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _compiler_self_probe_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    source = ctx.actions.declare_file(ctx.label.name + ".S")
    obj = ctx.actions.declare_file(ctx.label.name + ".o")
    ctx.actions.write(source, ".text\n.globl hermetic_cc1_probe\nhermetic_cc1_probe:\n  ret\n")
    ctx.actions.run(
        executable = foreign.clang,
        arguments = [
            "--target=" + foreign.exec_triple,
            "-c",
            source.path,
            "-o",
            obj.path,
        ],
        inputs = depset([source], transitive = [foreign.compiler_files]),
        outputs = [obj],
        env = dict(foreign.env, **{
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "HermeticCompilerSelfProbe",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([obj]))]

compiler_self_probe = rule(
    implementation = _compiler_self_probe_impl,
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _arm_exec_closure_probe_impl(ctx):
    out = ctx.actions.declare_file(ctx.label.name + ".txt")
    ctx.actions.run(
        executable = ctx.executable.busybox,
        arguments = [
            "sh",
            ctx.file._probe.path,
            ctx.executable.busybox.path,
            ctx.executable.launcher.path,
            ctx.executable.busybox.dirname + "/..",
            out.path,
        ],
        inputs = depset(
            [ctx.file._probe, ctx.executable.busybox, ctx.executable.launcher],
            transitive = [depset(ctx.files.runtime)],
        ),
        outputs = [out],
        env = {"LANG": "C", "LC_ALL": "C", "PATH": "/nonexistent"},
        execution_requirements = {"no-network": "1"},
        mnemonic = "HermeticArmExecClosureProbe",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([out]))]

arm_exec_closure_probe = rule(
    implementation = _arm_exec_closure_probe_impl,
    attrs = {
        "busybox": attr.label(allow_single_file = True, cfg = "exec", executable = True, mandatory = True),
        "launcher": attr.label(cfg = "target", executable = True, mandatory = True),
        "runtime": attr.label_list(allow_files = True, mandatory = True),
        "_probe": attr.label(
            allow_single_file = True,
            default = "//tools/bazel:arm-exec-closure-probe",
        ),
    },
)
