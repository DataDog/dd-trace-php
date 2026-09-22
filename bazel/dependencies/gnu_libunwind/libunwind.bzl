"""Target-configured GNU libunwind 1.8.3 adapter for libdd-libunwind-sys."""

load("@rules_cc//cc:find_cc_toolchain.bzl", "find_cc_toolchain")
load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/dependencies/llvm_runtimes:runtime.bzl", "LlvmRuntimeInfo")

GnuLibunwindInfo = provider(
    fields = ["archives", "asan", "headers", "runtime", "smoke", "target_triple"],
    doc = "GNU libunwind archives in Cargo's required static link order.",
)

def _gnu_libunwind_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    runtime = ctx.attr.runtime[LlvmRuntimeInfo]
    if runtime.target_triple != sysroot.target_triple or runtime.libc != sysroot.libc:
        fail("GNU libunwind runtime and sysroot must describe the same target")
    asan = ctx.target_platform_has_constraint(ctx.attr._asan_constraint[platform_common.ConstraintValueInfo])
    if asan and (not runtime.asan_supported or not runtime.asan_shared):
        fail("ASan GNU libunwind requires the matching declared LLVM ASan runtime")
    cc_toolchain = find_cc_toolchain(ctx)
    feature_configuration = cc_common.configure_features(
        ctx = ctx,
        cc_toolchain = cc_toolchain,
        requested_features = ctx.features,
        unsupported_features = ctx.disabled_features,
    )

    # The foreign tools are declared by the execution-tool toolchain. Do not
    # ask Autoconf to infer a compiler through Bazel's legacy `gcc` tool path.
    compiler = foreign.clang.path
    archiver = foreign.ar.path
    prefix = ctx.actions.declare_directory(ctx.label.name + ".prefix")
    unwind = ctx.actions.declare_file(ctx.label.name + ".libunwind.a")
    ptrace = ctx.actions.declare_file(ctx.label.name + ".libunwind-ptrace.a")
    arch_suffix = "aarch64" if sysroot.target_triple.startswith("aarch64-") else "x86_64"
    arch = ctx.actions.declare_file(ctx.label.name + ".libunwind-" + arch_suffix + ".a")
    smoke = ctx.actions.declare_file(ctx.label.name + ".smoke")
    shared_smoke = ctx.actions.declare_file(ctx.label.name + ".shared-smoke.so")
    args = ctx.actions.args()
    args.add(ctx.file._runner.path)
    args.add_all(["--configure", ctx.file.configure.path])
    args.add_all(["--prefix", prefix.path])
    args.add_all(["--unwind", unwind.path])
    args.add_all(["--ptrace", ptrace.path])
    args.add_all(["--arch", arch.path])
    args.add_all(["--smoke", smoke.path])
    args.add_all(["--smoke-source", ctx.file._smoke_source.path])
    args.add_all(["--shared-smoke", shared_smoke.path])
    args.add_all(["--shared-smoke-source", ctx.file._shared_smoke_source.path])
    args.add_all(["--cc", compiler])
    args.add_all(["--cxx", foreign.clangxx.path])
    args.add_all(["--ar", archiver])
    args.add_all(["--ranlib", foreign.ranlib.path])
    args.add_all(["--nm", foreign.nm.path])
    args.add_all(["--ld", foreign.lld.path])
    args.add_all(["--make", foreign.make.path])
    args.add_all(["--shell", foreign.shell.path])
    args.add_all(["--objdump", foreign.objdump.path])
    args.add_all(["--build", foreign.exec_triple])
    args.add_all(["--target", sysroot.target_triple])
    args.add_all(["--sysroot", sysroot.root.dirname])
    args.add_all(["--crtbegin", runtime.crtbegin.path])
    args.add_all(["--crtend", runtime.crtend.path])
    args.add_all(["--builtins", runtime.builtins_static.path])
    args.add_all(["--runtime-unwind", runtime.libunwind_static.path])
    if asan:
        args.add_all(["--asan", "--asan-shared", runtime.asan_shared.path, "--target-resource-dir", runtime.resource_dir.path])
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [args],
        inputs = depset(
            ctx.files.srcs + [ctx.file.configure, ctx.file._runner, ctx.file._smoke_source, ctx.file._shared_smoke_source],
            transitive = [cc_toolchain.all_files, foreign.files, foreign.compiler_files, runtime.files, sysroot.files],
        ),
        outputs = [prefix, unwind, ptrace, arch, smoke, shared_smoke],
        env = dict(
            foreign.env,
            HOME = "/nonexistent",
            LANG = "C",
            LC_ALL = "C",
            PATH = ":".join(foreign.path_entries),
            SOURCE_DATE_EPOCH = "0",
            TZ = "UTC",
            ZERO_AR_DATE = "1",
        ),
        execution_requirements = {"no-network": "1"},
        mnemonic = "BuildGnuLibunwind",
        progress_message = "Cross-building GNU libunwind 1.8.3 for %s" % sysroot.target_triple,
    )
    libraries = []
    for archive in [unwind, ptrace, arch]:
        libraries.append(cc_common.create_library_to_link(
            actions = ctx.actions,
            cc_toolchain = cc_toolchain,
            feature_configuration = feature_configuration,
            static_library = archive,
        ))
    linker_input = cc_common.create_linker_input(
        owner = ctx.label,
        libraries = depset(direct = libraries),
    )
    compilation_context = cc_common.create_compilation_context(
        headers = depset([prefix]),
        includes = depset([prefix.path + "/include"]),
    )
    linking_context = cc_common.create_linking_context(
        linker_inputs = depset(direct = [linker_input]),
    )
    info = GnuLibunwindInfo(
        archives = (unwind, ptrace, arch),
        asan = asan,
        headers = prefix,
        runtime = runtime,
        smoke = smoke,
        target_triple = sysroot.target_triple,
    )
    return [
        DefaultInfo(files = depset([prefix, unwind, ptrace, arch, smoke, shared_smoke])),
        info,
        CcInfo(compilation_context = compilation_context, linking_context = linking_context),
    ]

gnu_libunwind = rule(
    implementation = _gnu_libunwind_impl,
    attrs = {
        "configure": attr.label(allow_single_file = True, mandatory = True),
        "runtime": attr.label(mandatory = True, providers = [LlvmRuntimeInfo]),
        "srcs": attr.label(allow_files = True, mandatory = True),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/gnu_libunwind:build-libunwind.sh",
        ),
        "_smoke_source": attr.label(
            allow_single_file = [".c"],
            default = "//bazel/dependencies/gnu_libunwind:unwind-smoke.c",
        ),
        "_shared_smoke_source": attr.label(
            allow_single_file = [".c"],
            default = "//bazel/dependencies/gnu_libunwind:unwind-shared-smoke.c",
        ),
        "_asan_constraint": attr.label(default = "//bazel/platforms:asan"),
    },
    fragments = ["cpp"],
    toolchains = [
        "@bazel_tools//tools/cpp:toolchain_type",
        "//bazel/toolchains:hermetic_tools_type",
        "//bazel/toolchains:sysroot_type",
    ],
)

def _gnu_libunwind_native_probe_impl(ctx):
    unwind = ctx.attr.library[GnuLibunwindInfo]
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = ctx.executable.busybox,
        arguments = ["sh", ctx.file._runner.path, ctx.executable.busybox.path, unwind.smoke.path, marker.path],
        inputs = [ctx.executable.busybox, ctx.file._runner, unwind.smoke],
        outputs = [marker],
        env = {"HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C", "PATH": "/nonexistent", "TZ": "UTC"},
        execution_requirements = {"no-network": "1"},
        mnemonic = "RunGnuLibunwindNativeSmoke",
    )
    return [DefaultInfo(files = depset([marker]))]

gnu_libunwind_native_probe = rule(
    implementation = _gnu_libunwind_native_probe_impl,
    attrs = {
        "busybox": attr.label(allow_single_file = True, cfg = "exec", executable = True, mandatory = True),
        "library": attr.label(mandatory = True, providers = [GnuLibunwindInfo]),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/gnu_libunwind:run-native-smoke.sh",
        ),
    },
)

def _gnu_libunwind_asan_native_probe_impl(ctx):
    unwind = ctx.attr.library[GnuLibunwindInfo]
    runtime = unwind.runtime
    if not unwind.asan or not runtime.asan_supported or not runtime.asan_shared:
        fail("ASan GNU libunwind native probe requires ASan-built archives and runtime")
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    if runtime.target_triple != sysroot.target_triple or unwind.target_triple != sysroot.target_triple or runtime.libc != "glibc":
        fail("ASan GNU libunwind native probe requires matching glibc target inputs")
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    library_path = ":".join([runtime.asan_shared.dirname, sysroot.root.dirname + "/lib", sysroot.root.dirname + "/lib64", sysroot.root.dirname + "/usr/lib", sysroot.root.dirname + "/usr/lib64"])
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [ctx.file._runner.path, foreign.objdump.path, sysroot.root.dirname + sysroot.dynamic_linker, library_path, unwind.smoke.path, marker.path, ctx.attr.machine, ctx.attr.elf_architecture, runtime.asan_shared_basename],
        inputs = depset([ctx.file._runner, unwind.smoke], transitive = [foreign.files, foreign.compiler_files, runtime.files, sysroot.files]),
        outputs = [marker],
        env = dict(foreign.env, HOME = "/nonexistent", LANG = "C", LC_ALL = "C", PATH = ":".join(foreign.path_entries), TZ = "UTC"),
        execution_requirements = {"no-network": "1"},
        mnemonic = "RunGnuLibunwindAsanNativeSmoke",
    )
    return [DefaultInfo(files = depset([marker])), OutputGroupInfo(binary = depset([unwind.smoke]))]

gnu_libunwind_asan_native_probe = rule(
    implementation = _gnu_libunwind_asan_native_probe_impl,
    attrs = {
        "elf_architecture": attr.string(mandatory = True),
        "library": attr.label(mandatory = True, providers = [GnuLibunwindInfo]),
        "machine": attr.string(mandatory = True),
        "_runner": attr.label(allow_single_file = True, default = "//bazel/dependencies/gnu_libunwind:run-asan-unwind-smoke.sh"),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)
