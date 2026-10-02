"""Declared static ELF inspector for the Rust target/exec boundary smoke."""

def _static_elf_checker_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name + "/elf_dynamic_check")
    object_file = ctx.actions.declare_file(ctx.label.name + "/elf_dynamic_check.o")
    musl_sysroot = ctx.file.crt1.dirname + "/../.."
    compiler_library_path = ":".join(sorted({file.dirname: None for file in ctx.files.compiler_libraries}.keys()))

    compile_args = ctx.actions.args()
    compile_args.add_all([
        "--library-path",
        compiler_library_path,
        ctx.executable.clang.path,
        "--target=x86_64-alpine-linux-musl",
        "--sysroot=" + musl_sysroot,
        "-resource-dir",
        ctx.executable.clang.dirname + "/../lib/clang/20",
        "-std=c11",
        "-D_GNU_SOURCE",
        "-Os",
        "-fno-ident",
        "-Wall",
        "-Wextra",
        "-Werror",
        "-c",
        ctx.file.source.path,
        "-o",
        object_file.path,
    ])
    ctx.actions.run(
        arguments = [compile_args],
        env = {},
        executable = ctx.executable.compiler_loader,
        execution_requirements = {"no-network": "1"},
        inputs = depset(
            direct = [ctx.file.source],
            transitive = [depset(ctx.files.build_runtime)],
        ),
        mnemonic = "CompileStaticElfChecker",
        outputs = [object_file],
        tools = [ctx.executable.clang] + ctx.files.compiler_runtime,
    )

    link_args = ctx.actions.args()
    link_args.add_all([
        "--library-path",
        compiler_library_path,
        ctx.executable.lld.path,
        "-m",
        "elf_x86_64",
        "-static",
        "--build-id=none",
        "-o",
        output.path,
        ctx.file.crt1.path,
        ctx.file.crti.path,
        object_file.path,
        "-L" + ctx.file.libc.dirname,
        "-lc",
        ctx.file.crtn.path,
    ])
    ctx.actions.run(
        arguments = [link_args],
        env = {},
        executable = ctx.executable.compiler_loader,
        execution_requirements = {"no-network": "1"},
        inputs = depset(
            direct = [ctx.file.crt1, ctx.file.crti, ctx.file.crtn, ctx.file.libc, object_file],
            transitive = [depset(ctx.files.build_runtime)],
        ),
        mnemonic = "LinkStaticElfChecker",
        outputs = [output],
        tools = [ctx.executable.lld] + ctx.files.compiler_runtime,
    )
    return [DefaultInfo(executable = output, files = depset([output]))]

static_elf_checker = rule(
    implementation = _static_elf_checker_impl,
    executable = True,
    attrs = {
        "build_runtime": attr.label_list(allow_empty = False, allow_files = True),
        "clang": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "compiler_libraries": attr.label_list(allow_empty = False, allow_files = True),
        "compiler_loader": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "compiler_runtime": attr.label_list(allow_empty = False, allow_files = True),
        "crt1": attr.label(allow_single_file = True, mandatory = True),
        "crti": attr.label(allow_single_file = True, mandatory = True),
        "crtn": attr.label(allow_single_file = True, mandatory = True),
        "libc": attr.label(allow_single_file = True, mandatory = True),
        "lld": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "source": attr.label(allow_single_file = [".c"], mandatory = True),
    },
)

def _elf_dynamic_check_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name + ".ok")
    ctx.actions.run(
        arguments = [ctx.file.binary.path, output.path, ctx.attr.interpreter],
        env = {},
        executable = ctx.executable.checker,
        execution_requirements = {"no-network": "1"},
        inputs = [ctx.file.binary],
        mnemonic = "CheckRustTargetElf",
        outputs = [output],
        tools = [ctx.attr.checker[DefaultInfo].files_to_run],
    )
    return [DefaultInfo(files = depset([output]))]

elf_dynamic_check = rule(
    implementation = _elf_dynamic_check_impl,
    attrs = {
        "binary": attr.label(allow_single_file = True, mandatory = True),
        "checker": attr.label(cfg = "exec", executable = True, mandatory = True),
        "interpreter": attr.string(mandatory = True),
    },
)
