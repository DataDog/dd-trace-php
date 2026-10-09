"""Pinned compiler tools with no dependency on the target C++ toolchain."""

BootstrapToolsInfo = provider(fields = [
    "loader",
    "library_path",
    "clang",
    "clangxx",
    "ar",
    "nm",
    "objcopy",
    "objdump",
    "lld",
    "busybox",
    "resource_include",
    "resource_dir",
    "resource_headers",
    "compile_files",
    "link_files",
    "archive_files",
    "inspect_files",
    "env",
])

def _bootstrap_tools_impl(ctx):
    runtime = depset(ctx.files.execution_libraries + [ctx.executable.loader])
    resource_headers = depset([f for f in ctx.files.headers if f.path.endswith("/lib/clang/20/include")])
    compile_files = depset(
        [ctx.executable.clang, ctx.executable.clangxx],
        transitive = [runtime, resource_headers],
    )
    info = BootstrapToolsInfo(
        loader = ctx.executable.loader,
        library_path = ":".join(sorted({f.dirname: True for f in ctx.files.library_anchors})),
        clang = ctx.executable.clang,
        clangxx = ctx.executable.clangxx,
        ar = ctx.executable.ar,
        nm = ctx.executable.nm,
        objcopy = ctx.executable.objcopy,
        objdump = ctx.executable.objdump,
        lld = ctx.executable.lld,
        busybox = ctx.executable.busybox,
        resource_include = ctx.file.header_anchor.path,
        resource_dir = ctx.file.header_anchor.dirname,
        resource_headers = resource_headers,
        compile_files = compile_files,
        link_files = depset([ctx.executable.lld, ctx.file.raw_lld, ctx.executable.shell], transitive = [compile_files]),
        archive_files = depset([ctx.executable.ar], transitive = [runtime]),
        inspect_files = depset([ctx.executable.nm, ctx.executable.objcopy, ctx.executable.objdump, ctx.file.raw_strip, ctx.executable.shell], transitive = [runtime]),
        env = {
            "HERMETIC_EXEC_RUNTIME_ROOT": ctx.executable.loader.dirname + "/../..",
            "HERMETIC_LLVM_ROOT": ctx.executable.clang.dirname + "/..",
            "HERMETIC_TOOLS_ROOT": ctx.executable.busybox.dirname + "/..",
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ctx.executable.shell.dirname,
            "SOURCE_DATE_EPOCH": "0",
            "TZ": "UTC",
            "ZERO_AR_DATE": "1",
        },
    )
    return [platform_common.ToolchainInfo(bootstrap = info)]

_bootstrap_tools = rule(
    implementation = _bootstrap_tools_impl,
    attrs = dict(
        {name: attr.label(executable = True, cfg = "exec", allow_single_file = True, mandatory = True) for name in ["loader", "clang", "clangxx", "ar", "nm", "objcopy", "objdump", "lld", "busybox", "shell"]},
        execution_libraries = attr.label_list(allow_files = True),
        headers = attr.label_list(allow_files = True),
        library_anchors = attr.label_list(allow_files = True),
        header_anchor = attr.label(allow_single_file = True),
        raw_lld = attr.label(allow_single_file = True),
        raw_strip = attr.label(allow_single_file = True),
    ),
)

def bootstrap_toolchains():
    for arch in ["x86_64", "aarch64"]:
        llvm = "@llvm_20_1_4_dist_%s//:" % arch
        execution = "@exec_runtime_debian12_%s//:" % arch
        _bootstrap_tools(
            name = "bootstrap_" + arch,
            ar = llvm + "bin/llvm-ar",
            busybox = "@exec_tools_alpine322_%s//:root/bin/busybox.static" % arch,
            clang = llvm + "bin/clang",
            clangxx = llvm + "bin/clang++",
            execution_libraries = [execution + "all"],
            header_anchor = llvm + "lib/clang/20/include",
            headers = [llvm + "cxx_builtin_include"],
            library_anchors = [execution + "lib_anchor", execution + "usr_lib_anchor"],
            lld = "//bazel/dependencies/exec_tools:ld.lld",
            loader = execution + "loader",
            nm = llvm + "bin/llvm-nm",
            objcopy = llvm + "bin/llvm-objcopy",
            objdump = llvm + "bin/llvm-objdump",
            raw_lld = llvm + "bin/ld.lld",
            raw_strip = llvm + "bin/llvm-strip",
            shell = "//bazel/dependencies/exec_tools:sh",
        )
        native.toolchain(
            name = "bootstrap_%s_toolchain" % arch,
            exec_compatible_with = ["@platforms//os:linux", "@platforms//cpu:" + arch],
            toolchain = ":bootstrap_" + arch,
            toolchain_type = ":bootstrap_tools_type",
        )
