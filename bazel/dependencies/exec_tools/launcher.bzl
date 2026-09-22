"""Static musl launcher and command-alias rules for execution tools."""

def _exec_launcher_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name)
    object_file = ctx.actions.declare_file(ctx.label.name + ".o")
    sysroot = ctx.file.crt1.dirname + "/../.."
    compiler_library_path = ":".join(sorted({file.dirname: None for file in ctx.files.compiler_libraries}.keys()))

    compile_args = ctx.actions.args()
    compile_args.add_all([
        "--library-path",
        compiler_library_path,
        ctx.executable.clang.path,
        "--target=" + ctx.attr.target_triple,
        "--sysroot=" + sysroot,
        "-resource-dir",
        ctx.executable.clang.dirname + "/../lib/clang/20",
        "-c",
        "-Os",
        "-fno-ident",
        "-Wall",
        "-Wextra",
        "-Werror",
        ctx.file.source.path,
        "-o",
        object_file.path,
    ])
    compile_inputs = depset(
        direct = [ctx.file.source],
        transitive = [depset(ctx.files.runtime)],
    )
    ctx.actions.run(
        arguments = [compile_args],
        env = {},
        executable = ctx.executable.compiler_loader,
        execution_requirements = {"no-network": "1"},
        inputs = compile_inputs,
        mnemonic = "CompileHermeticExecLauncher",
        outputs = [object_file],
        progress_message = "Compiling static %s execution-tool launcher" % ctx.attr.target_triple,
        tools = [ctx.executable.clang] + ctx.files.compiler_runtime,
    )

    link_args = ctx.actions.args()
    link_args.add_all([
        "--library-path",
        compiler_library_path,
        ctx.executable.lld.path,
        "-m",
        ctx.attr.lld_emulation,
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
    link_inputs = depset(
        direct = [
            ctx.file.crt1,
            ctx.file.crti,
            ctx.file.crtn,
            ctx.file.libc,
            object_file,
        ],
        transitive = [depset(ctx.files.runtime)],
    )
    ctx.actions.run(
        arguments = [link_args],
        env = {},
        executable = ctx.executable.compiler_loader,
        execution_requirements = {"no-network": "1"},
        inputs = link_inputs,
        mnemonic = "LinkHermeticExecLauncher",
        outputs = [output],
        progress_message = "Linking static %s execution-tool launcher" % ctx.attr.target_triple,
        tools = [ctx.executable.lld] + ctx.files.compiler_runtime,
    )
    return [DefaultInfo(
        executable = output,
        files = depset([output]),
    )]

exec_launcher = rule(
    implementation = _exec_launcher_impl,
    executable = True,
    attrs = {
        "clang": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "compiler_libraries": attr.label_list(allow_empty = False, allow_files = True),
        "compiler_loader": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "compiler_runtime": attr.label_list(allow_files = True),
        "crt1": attr.label(allow_single_file = True, mandatory = True),
        "crti": attr.label(allow_single_file = True, mandatory = True),
        "crtn": attr.label(allow_single_file = True, mandatory = True),
        "libc": attr.label(allow_single_file = True, mandatory = True),
        "lld": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "lld_emulation": attr.string(mandatory = True),
        "runtime": attr.label_list(allow_empty = False, allow_files = True),
        "source": attr.label(allow_single_file = [".c"], mandatory = True),
        "target_triple": attr.string(mandatory = True),
    },
)

def _launcher_alias_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name)
    ctx.actions.symlink(
        is_executable = True,
        output = output,
        target_file = ctx.executable.launcher,
    )
    return [DefaultInfo(
        executable = output,
        files = depset([output]),
        runfiles = ctx.runfiles(files = [ctx.executable.launcher]),
    )]

launcher_alias = rule(
    implementation = _launcher_alias_impl,
    executable = True,
    attrs = {
        "launcher": attr.label(cfg = "exec", executable = True, mandatory = True),
    },
)

def _cmake_bundle_impl(ctx):
    executable = ctx.actions.declare_file(ctx.label.name + "/bin/cmake")
    modules = ctx.actions.declare_directory(ctx.label.name + "/share/cmake")
    ctx.actions.run(
        executable = ctx.executable.shell,
        arguments = [
            ctx.file._bundle_script.path,
            ctx.executable.busybox.path,
            ctx.executable.launcher.path,
            ctx.file.modules_anchor.dirname + "/..",
            executable.path,
            modules.path,
        ],
        inputs = depset(
            [ctx.file._bundle_script, ctx.file.modules_anchor, ctx.executable.busybox, ctx.executable.launcher],
            transitive = [depset(ctx.files.runtime)],
        ),
        outputs = [executable, modules],
        env = {},
        execution_requirements = {"no-network": "1"},
        mnemonic = "BundleHermeticCMake",
    )
    return [DefaultInfo(
        executable = executable,
        files = depset([executable, modules]),
    )]

cmake_bundle = rule(
    implementation = _cmake_bundle_impl,
    executable = True,
    attrs = {
        "busybox": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "launcher": attr.label(cfg = "exec", executable = True, mandatory = True),
        "modules_anchor": attr.label(allow_single_file = True, mandatory = True),
        "runtime": attr.label_list(allow_files = True),
        "shell": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "_bundle_script": attr.label(
            allow_single_file = True,
            default = "//tools/bazel:bundle-cmake",
        ),
    },
)

def execution_tool_aliases(launcher):
    """Declares executable aliases whose output basenames select an applet."""
    commands = (
        "aclocal",
        "autom4te",
        "automake",
        "autoconf",
        "autoheader",
        "autoreconf",
        "autoscan",
        "autoupdate",
        "awk",
        "basename",
        "bash",
        "bison",
        "cat",
        "chmod",
        "chown",
        "cksum",
        "cmp",
        "comm",
        "coreutils",
        "cp",
        "cpack",
        "ctest",
        "cut",
        "date",
        "dd",
        "df",
        "diff",
        "diff3",
        "dirname",
        "du",
        "echo",
        "env",
        "expr",
        "false",
        "file",
        "find",
        "grep",
        "gzip",
        "head",
        "id",
        "ifnames",
        "install",
        "libtool",
        "libtoolize",
        "clang",
        "clang++",
        "clang-cpp",
        "ld.lld",
        "llvm-ar",
        "llvm-nm",
        "llvm-objcopy",
        "llvm-objdump",
        "llvm-ranlib",
        "llvm-strip",
        "ln",
        "ls",
        "m4",
        "make",
        "mkdir",
        "mkfifo",
        "mknod",
        "mktemp",
        "mv",
        "nice",
        "nl",
        "nproc",
        "od",
        "paste",
        "patch",
        "perl",
        "pkg-config",
        "pkgconf",
        "printf",
        "pwd",
        "python",
        "python3",
        "readlink",
        "realpath",
        "re2c",
        "rm",
        "rmdir",
        "sed",
        "sha1sum",
        "sha256sum",
        "sh",
        "sleep",
        "sort",
        "split",
        "stat",
        "sum",
        "sync",
        "tac",
        "tail",
        "tar",
        "tee",
        "test",
        "timeout",
        "touch",
        "tr",
        "true",
        "truncate",
        "tsort",
        "tty",
        "uname",
        "uniq",
        "wc",
        "which",
        "whoami",
        "xargs",
        "yes",
    )
    for command in commands:
        launcher_alias(
            name = command,
            launcher = launcher,
        )
    native.filegroup(
        name = "launchers_all",
        srcs = [":" + command for command in commands],
    )
