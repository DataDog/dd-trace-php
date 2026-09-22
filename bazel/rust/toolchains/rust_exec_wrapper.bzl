"""Static launchers for pinned GNU Rust compiler-distribution tools."""

load("//bazel/dependencies/llvm_runtimes:runtime.bzl", "LlvmRuntimeInfo")

def _c_string(value):
    if "\\" in value or "\"" in value or "\n" in value:
        fail("tool path cannot be represented as a C define: %r" % value)
    return "\"%s\"" % value

def _rust_exec_wrapper_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name + "/" + ctx.attr.output_basename)
    object_file = ctx.actions.declare_file(ctx.label.name + "/rust_exec_wrapper.o")
    musl_sysroot = ctx.file.crt1.dirname + "/../.."
    compiler_library_path = ":".join(sorted({file.dirname: None for file in ctx.files.compiler_libraries}.keys()))
    static_exec_library_dirs = {file.dirname: None for file in ctx.files.static_exec_libraries}.keys()
    if len(static_exec_library_dirs) != 1:
        fail("static execution libraries must share one directory, got %s" % static_exec_library_dirs)
    static_exec_library_dir = static_exec_library_dirs[0]
    target_x86_64_gnu_library_dirs = {file.dirname: None for file in ctx.files.target_x86_64_gnu_libraries}.keys()
    target_aarch64_gnu_library_dirs = {file.dirname: None for file in ctx.files.target_aarch64_gnu_libraries}.keys()
    if len(target_x86_64_gnu_library_dirs) != 1 or len(target_aarch64_gnu_library_dirs) != 1:
        fail("target GNU runtime libraries must have one directory per architecture")

    raw_tool_path = ctx.file.raw_tool.path
    loader_path = ctx.file.exec_loader.path
    for name, path in [("raw tool", raw_tool_path), ("execution loader", loader_path)]:
        if path.startswith("/"):
            fail("%s must have an execroot-relative path, got %s" % (name, path))

    library_dirs = sorted({
        file.dirname: None
        for file in ctx.files.raw_runtime
        if ".so" in file.basename or file.basename.startswith("ld-linux-")
    }.keys())
    if not library_dirs:
        fail("raw Rust tool closure has no declared dynamic-library directories")

    compile_args = ctx.actions.args()
    compile_args.add_all([
        "--library-path",
        compiler_library_path,
        ctx.executable.clang.path,
        "--target=" + ctx.attr.target_triple,
        "--sysroot=" + musl_sysroot,
        "-resource-dir",
        ctx.executable.clang.dirname + "/../lib/clang/20",
        "-std=c11",
        "-Os",
        "-fno-ident",
        "-Wall",
        "-Wextra",
        "-Werror",
        "-DRAW_TOOL_PATH=" + _c_string(raw_tool_path),
        "-DEXEC_LOADER_PATH=" + _c_string(loader_path),
        "-DDECLARED_LIBRARY_DIRS=" + _c_string(":".join(library_dirs)),
        "-DAUTO_STATIC_EXEC_BIN=%d" % (1 if ctx.attr.auto_static_exec_bin else 0),
        "-DSTATIC_EXEC_LIBRARY_DIR=" + _c_string(static_exec_library_dir),
        "-DTARGET_X86_64_GNU_LIBRARY_DIR=" + _c_string(target_x86_64_gnu_library_dirs[0]),
        "-DTARGET_AARCH64_GNU_LIBRARY_DIR=" + _c_string(target_aarch64_gnu_library_dirs[0]),
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
        mnemonic = "CompileHermeticRustExecWrapper",
        outputs = [object_file],
        progress_message = "Compiling static %s Rust execution wrapper" % ctx.attr.output_basename,
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
    ctx.actions.run(
        arguments = [link_args],
        env = {},
        executable = ctx.executable.compiler_loader,
        execution_requirements = {"no-network": "1"},
        inputs = depset(
            direct = [
                ctx.file.crt1,
                ctx.file.crti,
                ctx.file.crtn,
                ctx.file.libc,
                object_file,
            ],
            transitive = [depset(ctx.files.build_runtime)],
        ),
        mnemonic = "LinkHermeticRustExecWrapper",
        outputs = [output],
        progress_message = "Linking static %s Rust execution wrapper" % ctx.attr.output_basename,
        tools = [ctx.executable.lld] + ctx.files.compiler_runtime,
    )

    runtime = depset(
        direct = [ctx.file.exec_loader, ctx.file.raw_tool] +
                 ctx.files.static_exec_libraries +
                 ctx.files.target_x86_64_gnu_libraries +
                 ctx.files.target_aarch64_gnu_libraries,
        transitive = [depset(ctx.files.raw_runtime)],
    )
    return [DefaultInfo(
        executable = output,
        files = depset([output]),
        runfiles = ctx.runfiles(transitive_files = runtime),
    )]

rust_exec_wrapper = rule(
    implementation = _rust_exec_wrapper_impl,
    executable = True,
    attrs = {
        "build_runtime": attr.label_list(allow_empty = False, allow_files = True),
        "auto_static_exec_bin": attr.bool(),
        "clang": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "compiler_libraries": attr.label_list(allow_empty = False, allow_files = True),
        "compiler_loader": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "compiler_runtime": attr.label_list(allow_files = True),
        "crt1": attr.label(allow_single_file = True, mandatory = True),
        "crti": attr.label(allow_single_file = True, mandatory = True),
        "crtn": attr.label(allow_single_file = True, mandatory = True),
        "exec_loader": attr.label(allow_single_file = True, mandatory = True),
        "libc": attr.label(allow_single_file = True, mandatory = True),
        "lld": attr.label(allow_files = True, cfg = "exec", executable = True, mandatory = True),
        "lld_emulation": attr.string(mandatory = True),
        "output_basename": attr.string(mandatory = True),
        "raw_runtime": attr.label_list(allow_empty = False, allow_files = True),
        "raw_tool": attr.label(allow_single_file = True, mandatory = True),
        "source": attr.label(
            allow_single_file = [".c"],
            default = ":rust_exec_wrapper.c",
        ),
        "static_exec_libraries": attr.label_list(allow_empty = False, allow_files = True),
        "target_aarch64_gnu_libraries": attr.label_list(allow_empty = False, allow_files = True),
        "target_triple": attr.string(mandatory = True),
        "target_x86_64_gnu_libraries": attr.label_list(allow_empty = False, allow_files = True),
    },
)

def _rust_static_exec_libraries_impl(ctx):
    runtime_target = ctx.attr.runtime
    if type(runtime_target) == "list":
        if len(runtime_target) != 1:
            fail("target runtime transition resolved %d variants" % len(runtime_target))
        runtime_target = runtime_target[0]
    runtime = runtime_target[LlvmRuntimeInfo]
    libgcc = ctx.actions.declare_file(ctx.label.name + "/libgcc.a")
    libgcc_eh = ctx.actions.declare_file(ctx.label.name + "/libgcc_eh.a")
    libgcc_s = ctx.actions.declare_file(ctx.label.name + "/libgcc_s.so")
    ctx.actions.symlink(output = libgcc, target_file = runtime.builtins_static)
    ctx.actions.symlink(output = libgcc_eh, target_file = runtime.libunwind_static)
    ctx.actions.symlink(output = libgcc_s, target_file = ctx.file.libgcc_s)
    return [DefaultInfo(files = depset([libgcc, libgcc_eh, libgcc_s]))]

rust_static_exec_libraries = rule(
    implementation = _rust_static_exec_libraries_impl,
    attrs = {
        "libgcc_s": attr.label(allow_single_file = True, mandatory = True),
        "runtime": attr.label(mandatory = True, providers = [LlvmRuntimeInfo]),
    },
)

def _rust_target_gnu_library_impl(ctx):
    # Rust's GNU standard libraries request -lgcc_s even when the selected
    # native toolchain uses compiler-rt and LLVM libunwind. This linker-name
    # adapter satisfies that standard EH ABI from the target runtime. It is
    # unrelated to libdd-libunwind-sys, which still requires GNU ptrace and
    # architecture archives from its dedicated dependency target.
    output = ctx.actions.declare_file(ctx.label.name + "/libgcc_s.so")
    runtime_target = ctx.attr.runtime
    if type(runtime_target) == "list":
        if len(runtime_target) != 1:
            fail("target runtime transition resolved %d variants" % len(runtime_target))
        runtime_target = runtime_target[0]
    runtime = runtime_target[LlvmRuntimeInfo]
    ctx.actions.symlink(output = output, target_file = runtime.libunwind_static)
    return [DefaultInfo(files = depset([output]))]

def _target_runtime_transition_impl(_settings, attr):
    return {"//command_line_option:platforms": attr.platform}

_target_runtime_transition = transition(
    implementation = _target_runtime_transition_impl,
    inputs = [],
    outputs = ["//command_line_option:platforms"],
)

rust_target_gnu_library = rule(
    implementation = _rust_target_gnu_library_impl,
    attrs = {
        "platform": attr.string(mandatory = True),
        "runtime": attr.label(
            cfg = _target_runtime_transition,
            mandatory = True,
            providers = [LlvmRuntimeInfo],
        ),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
    },
)

def rust_host_smoke(name, payload):
    _rust_host_smoke(
        name = name,
        payload = payload,
    )

def _rust_host_smoke_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name + ".ok")
    ctx.actions.run(
        arguments = [output.path],
        env = {},
        executable = ctx.executable.payload,
        execution_requirements = {"no-network": "1"},
        outputs = [output],
        tools = [ctx.attr.payload[DefaultInfo].files_to_run],
    )
    return [DefaultInfo(files = depset([output]))]

_rust_host_smoke = rule(
    implementation = _rust_host_smoke_impl,
    attrs = {
        "payload": attr.label(cfg = "exec", executable = True, mandatory = True),
    },
)
