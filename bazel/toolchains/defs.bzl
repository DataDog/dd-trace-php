"""Providers for hermetic foreign-build tools and target sysroots."""

HermeticToolsInfo = provider(
    doc = "Declared execution-time tools; wrapper and runtime gates determine which actions may consume them.",
    fields = {
        "files": "depset containing every executable and its runtime libraries",
        "path_entries": "ordered directories, relative to the execution root",
        "shell": "declared POSIX shell executable",
        "make": "declared GNU make executable",
        "cmake": "declared CMake executable",
        "patch": "declared patch executable",
        "tar": "declared deterministic archive executable",
        "env": "fixed environment additions",
        "exec_triple": "canonical execution-platform triple",
        "gzip": "declared deterministic gzip executable",
        "loader": "declared musl dynamic loader for execution tools",
        "busybox": "declared static BusyBox used for bootstrap commands",
        "clang": "declared Clang compiler launcher",
        "clangxx": "declared Clang C++ compiler launcher",
        "lld": "declared LLD linker launcher",
        "ar": "declared deterministic LLVM archiver launcher",
        "ranlib": "declared LLVM ranlib launcher",
        "nm": "declared LLVM symbol-table launcher",
        "objcopy": "declared LLVM object copier launcher",
        "objdump": "declared LLVM object inspector launcher",
        "strip": "declared LLVM strip launcher",
        "compiler_files": "depset containing LLVM and its execution runtime",
        "inspection_files": "ELF tools, launchers and their execution libraries only",
        "python_files": "Python launcher, interpreter, stdlib and execution libraries only",
    },
)

SysrootInfo = provider(
    doc = "Checksum-locked target libc and development inputs.",
    fields = {
        "files": "depset containing the full sysroot",
        "root": "tree artifact or package-root marker",
        "target_triple": "LLVM target triple",
        "libc": "glibc or musl",
        "libc_version": "compatibility floor",
        "dynamic_linker": "target ELF interpreter",
    },
)

def _hermetic_tools_impl(ctx):
    tools = ctx.files.tools + [
        ctx.executable.busybox,
        ctx.executable.gzip,
        ctx.executable.loader,
        ctx.executable.make,
        ctx.executable.patch,
        ctx.executable.shell,
        ctx.executable.tar,
        ctx.file.bin_anchor,
        ctx.file.usr_bin_anchor,
    ]
    if ctx.executable.cmake:
        tools.extend(ctx.files.cmake)
    compiler_files = depset(ctx.files.compiler_files + [
        ctx.executable.ar,
        ctx.executable.clang,
        ctx.executable.clangxx,
        ctx.executable.lld,
        ctx.executable.nm,
        ctx.executable.objcopy,
        ctx.executable.objdump,
        ctx.executable.ranlib,
        ctx.executable.shell,
        ctx.executable.strip,
        ctx.file.compiler_loader,
        ctx.file.llvm_anchor,
    ])
    info = HermeticToolsInfo(
        cmake = ctx.executable.cmake,
        busybox = ctx.executable.busybox,
        ar = ctx.executable.ar,
        clang = ctx.executable.clang,
        clangxx = ctx.executable.clangxx,
        compiler_files = compiler_files,
        inspection_files = depset([
            ctx.executable.nm,
            ctx.executable.objcopy,
            ctx.executable.objdump,
            ctx.executable.strip,
            ctx.executable.shell,
            ctx.executable.busybox,
        ] + [f for f in ctx.files.compiler_files if "exec_runtime_debian12_" in f.path or f.basename in ["llvm-nm", "llvm-objcopy", "llvm-objdump", "llvm-strip"]]),
        python_files = depset([ctx.executable.shell, ctx.executable.busybox] + [
            f
            for f in ctx.files.tools
            if f.basename.startswith("python3") or "/usr/lib/python" in f.path or ".so" in f.basename
        ]),
        env = dict(ctx.attr.env, **{
            "HERMETIC_EXEC_RUNTIME_ROOT": ctx.file.compiler_loader.dirname + "/../..",
            "HERMETIC_LLVM_ROOT": ctx.file.llvm_anchor.dirname[:-4],
            "HERMETIC_TOOLS_ROOT": ctx.executable.busybox.dirname + "/..",
            # Python tools run directly from the immutable extracted closure.
            # Never let imports create __pycache__ beside the standard library
            # and invalidate Bazel's external-repository content marker.
            "PYTHONDONTWRITEBYTECODE": "1",
            "PYTHONHASHSEED": "0",
            "PYTHONNOUSERSITE": "1",
        }),
        exec_triple = ctx.attr.exec_triple,
        files = depset(tools),
        gzip = ctx.executable.gzip,
        loader = ctx.executable.loader,
        lld = ctx.executable.lld,
        make = ctx.executable.make,
        patch = ctx.executable.patch,
        ranlib = ctx.executable.ranlib,
        nm = ctx.executable.nm,
        objcopy = ctx.executable.objcopy,
        objdump = ctx.executable.objdump,
        strip = ctx.executable.strip,
        path_entries = tuple([
            ctx.executable.cmake.dirname if ctx.executable.cmake else "",
            ctx.file.bin_anchor.dirname,
            ctx.file.usr_bin_anchor.dirname,
            ctx.executable.shell.dirname,
        ] + ctx.attr.path_entries),
        shell = ctx.executable.shell,
        tar = ctx.executable.tar,
    )
    return [platform_common.ToolchainInfo(foreign = info), DefaultInfo(files = info.files)]

hermetic_tools = rule(
    implementation = _hermetic_tools_impl,
    attrs = {
        "cmake": attr.label(allow_files = True, executable = True, cfg = "exec"),
        "busybox": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "ar": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "clang": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "clangxx": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "compiler_files": attr.label_list(allow_files = True),
        "compiler_loader": attr.label(allow_single_file = True, mandatory = True),
        "bin_anchor": attr.label(allow_single_file = True, mandatory = True),
        "env": attr.string_dict(),
        "exec_triple": attr.string(mandatory = True),
        "gzip": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "loader": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "lld": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "make": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "patch": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "ranlib": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "nm": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "objcopy": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "objdump": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "strip": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "path_entries": attr.string_list(),
        "shell": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "tar": attr.label(allow_single_file = True, executable = True, cfg = "exec", mandatory = True),
        "tools": attr.label_list(allow_files = True),
        "usr_bin_anchor": attr.label(allow_single_file = True, mandatory = True),
        "llvm_anchor": attr.label(allow_single_file = True, mandatory = True),
    },
)

def _sysroot_impl(ctx):
    if ctx.attr.libc == "glibc" and ctx.attr.libc_version != "2.17":
        fail("glibc targets must retain the 2.17 compatibility floor")
    if ctx.attr.libc == "musl" and ctx.attr.libc_version != "1.2.5":
        fail("Alpine 3.22 sysroots must declare musl 1.2.5")
    root = ctx.file.root
    info = SysrootInfo(
        dynamic_linker = ctx.attr.dynamic_linker,
        files = depset(ctx.files.files + [root]),
        libc = ctx.attr.libc,
        libc_version = ctx.attr.libc_version,
        root = root,
        target_triple = ctx.attr.target_triple,
    )
    return [info, platform_common.ToolchainInfo(sysroot = info), DefaultInfo(files = info.files)]

sysroot = rule(
    implementation = _sysroot_impl,
    attrs = {
        "dynamic_linker": attr.string(mandatory = True),
        "files": attr.label_list(allow_files = True),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "libc_version": attr.string(mandatory = True),
        "root": attr.label(allow_single_file = True, mandatory = True),
        "target_triple": attr.string(mandatory = True),
    },
)

def target_sysroot(name, repository, arch_constraint, libc_constraint, triple, libc, version, dynamic_linker):
    """Declares one sysroot implementation and its constrained toolchain."""
    sysroot(
        name = name,
        dynamic_linker = dynamic_linker,
        files = ["@%s//:all" % repository],
        libc = libc,
        libc_version = version,
        root = "@%s//:root" % repository,
        target_triple = triple,
    )
    native.toolchain(
        name = name + "_toolchain",
        target_compatible_with = [arch_constraint, libc_constraint, "@platforms//os:linux"],
        toolchain = ":" + name,
        toolchain_type = ":sysroot_type",
    )
