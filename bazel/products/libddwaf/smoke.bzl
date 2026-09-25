"""Runs the linked libddwaf smoke binary through the declared target loader."""

def _libddwaf_native_smoke_impl(ctx):
    binary = ctx.file.binary
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    root = sysroot.root.dirname
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._runner.path,
            foreign.objdump.path,
            root + sysroot.dynamic_linker,
            ":".join([root + "/lib", root + "/lib64", root + "/usr/lib", root + "/usr/lib64"]),
            binary.path,
            marker.path,
            ctx.attr.machine,
            ctx.attr.elf_architecture,
            sysroot.target_triple,
            sysroot.libc,
        ],
        inputs = depset(
            [binary, ctx.file._runner],
            transitive = [foreign.files, foreign.compiler_files, sysroot.files],
        ),
        outputs = [marker],
        env = dict(
            foreign.env,
            HOME = "/nonexistent",
            LANG = "C",
            LC_ALL = "C",
            PATH = ":".join(foreign.path_entries),
            TZ = "UTC",
        ),
        execution_requirements = {"no-network": "1"},
        mnemonic = "RunLibddwafNativeSmoke",
        progress_message = "Running linked libddwaf smoke on native %s" % ctx.attr.machine,
        use_default_shell_env = False,
    )
    # Export the exact executable that this action runs. Consumers can request
    # the `binary` output group without reconstructing a differently
    # configured target merely for inspection.
    return [
        DefaultInfo(files = depset([marker])),
        OutputGroupInfo(binary = depset([binary])),
    ]

_COMMON_ATTRS = {
    "binary": attr.label(allow_single_file = True, mandatory = True),
    "machine": attr.string(mandatory = True, values = ["x86_64", "aarch64"]),
    "elf_architecture": attr.string(mandatory = True),
    "_runner": attr.label(
        allow_single_file = True,
        default = "//bazel/products/libddwaf:run-smoke.sh",
    ),
}

libddwaf_native_smoke_x86_64 = rule(
    implementation = _libddwaf_native_smoke_impl,
    attrs = _COMMON_ATTRS,
    exec_compatible_with = ["@platforms//cpu:x86_64", "@platforms//os:linux"],
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)

libddwaf_native_smoke_aarch64 = rule(
    implementation = _libddwaf_native_smoke_impl,
    attrs = _COMMON_ATTRS,
    exec_compatible_with = ["@platforms//cpu:aarch64", "@platforms//os:linux"],
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)
