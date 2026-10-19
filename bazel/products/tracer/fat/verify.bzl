"""Split-debug publication and ELF validation for the monolithic tracer."""

def _split_tracer_debug_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    binary = ctx.actions.declare_file(ctx.label.name + "/ddtrace.so")
    debug = ctx.actions.declare_file(ctx.label.name + "/ddtrace.so.debug")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._splitter.path,
            foreign.objcopy.path,
            foreign.strip.path,
            ctx.file.binary.path,
            binary.path,
            debug.path,
        ],
        inputs = depset(
            [ctx.file._splitter, ctx.file.binary],
            transitive = [foreign.inspection_files],
        ),
        outputs = [binary, debug],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "SOURCE_DATE_EPOCH": "0",
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "SplitDdtraceDebug",
        use_default_shell_env = False,
    )
    return [
        DefaultInfo(files = depset([binary, debug])),
        OutputGroupInfo(binary = depset([binary]), debug = depset([debug])),
    ]

split_tracer_debug = rule(
    implementation = _split_tracer_debug_impl,
    attrs = {
        "binary": attr.label(allow_single_file = True, mandatory = True),
        "_splitter": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:split-debug-elf.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _tracer_fat_elf_check_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._runner.path,
            ctx.file._validator.path,
            ctx.file._debuglink_validator.path,
            foreign.objdump.path,
            foreign.nm.path,
            foreign.objcopy.path,
            ctx.file.binary.path,
            ctx.file.debug.path,
            ctx.file.expected_symbols.path,
            ctx.attr.architecture,
            ctx.attr.libc,
            marker.path,
        ],
        inputs = depset(
            [
                ctx.file._runner,
                ctx.file._validator,
                ctx.file._debuglink_validator,
                ctx.file.binary,
                ctx.file.debug,
                ctx.file.expected_symbols,
            ],
            transitive = [foreign.inspection_files, foreign.python_files],
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
        mnemonic = "ValidateDdtraceFatElf",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

tracer_fat_elf_check = rule(
    implementation = _tracer_fat_elf_check_impl,
    attrs = {
        "architecture": attr.string(mandatory = True, values = ["aarch64", "x86_64"]),
        "binary": attr.label(allow_single_file = True, mandatory = True),
        "debug": attr.label(allow_single_file = True, mandatory = True),
        "expected_symbols": attr.label(allow_single_file = True, mandatory = True),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "_debuglink_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:verify-debuglink.py",
        ),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer/fat:validate-fat-elf.sh",
        ),
        "_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer/fat:validate-fat-elf.py",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)
