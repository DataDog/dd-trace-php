"""Cross-builds the LLVM 20.1.4 C++ and compiler target runtimes."""

LlvmRuntimeInfo = provider(
    doc = "Relocatable libc++, libc++abi, libunwind, and compiler-rt builtins for one target.",
    fields = {
        "root": "Relocatable installed runtime tree artifact.",
        "files": "depset containing the tree and explicit link artifacts.",
        "headers": "depset containing the libc++ headers tree.",
        "libraries": "ordered tuple: libc++, libc++abi, libunwind, builtins.",
        "libcxx_static": "libc++.a File.",
        "libcxx_shared": "libc++.so.1 File.",
        "libcxxabi_static": "libc++abi.a File.",
        "libcxxabi_shared": "libc++abi.so.1 File.",
        "libunwind_static": "libunwind.a File.",
        "libunwind_shared": "libunwind.so.1 File.",
        "builtins_static": "compiler-rt builtins archive File.",
        "crtbegin": "compiler-rt crtbegin object File.",
        "crtend": "compiler-rt crtend object File.",
        "startup_crt1": "Target crt1.o with package-build DWARF removed.",
        "startup_pie_crt1": "Target PIE startup object with package-build DWARF removed.",
        "startup_crti": "Target crti.o with package-build DWARF removed.",
        "startup_crtn": "Target crtn.o with package-build DWARF removed.",
        "resource_dir": "Tree whose lib/linux directory contains compiler-rt artifacts.",
        "builtins_basename": "Target-specific compiler-rt builtins archive basename.",
        "asan_supported": "Whether this target runtime includes AddressSanitizer.",
        "asan_shared": "AddressSanitizer shared runtime File, or None.",
        "asan_static": "AddressSanitizer C static runtime File, or None.",
        "asan_cxx_static": "AddressSanitizer C++ static runtime File, or None.",
        "asan_preinit_static": "AddressSanitizer executable preinit archive File, or None.",
        "asan_shared_basename": "Canonical ASan DT_SONAME basename, or None.",
        "cxx20_smoke": "Static target C++20 validation executable File.",
        "target_triple": "LLVM target triple.",
        "libc": "glibc or musl.",
        "libc_version": "Target libc compatibility floor.",
    },
)

def _llvm_runtime_native_probe_impl(ctx):
    runtime = ctx.attr.runtime[LlvmRuntimeInfo]
    expected_arch = "aarch64" if runtime.target_triple.startswith("aarch64-") else "x86_64"

    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = ctx.executable.busybox,
        arguments = [
            "sh",
            ctx.file._native_probe_runner.path,
            expected_arch,
            ctx.executable.busybox.path,
            runtime.cxx20_smoke.path,
            marker.path,
        ],
        inputs = [ctx.file._native_probe_runner, ctx.executable.busybox, runtime.cxx20_smoke],
        outputs = [marker],
        env = {"HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C", "PATH": "/nonexistent", "TZ": "UTC"},
        exec_group = "native",
        execution_requirements = {"no-network": "1"},
        mnemonic = "RunLlvmRuntimeNativeSmoke",
        progress_message = "Running %s LLVM runtime smoke on matching native executor" % runtime.target_triple,
    )
    return [DefaultInfo(files = depset([marker]))]

llvm_runtime_native_probe = rule(
    implementation = _llvm_runtime_native_probe_impl,
    attrs = {
        "busybox": attr.label(
            allow_single_file = True,
            cfg = "target",
            default = "@exec_tools_alpine322_aarch64//:root/bin/busybox.static",
            executable = True,
        ),
        "runtime": attr.label(mandatory = True, providers = [LlvmRuntimeInfo]),
        "_native_probe_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/llvm_runtimes:run-native-smoke.sh",
        ),
    },
    exec_groups = {
        "native": exec_group(
            exec_compatible_with = ["@platforms//cpu:aarch64", "@platforms//os:linux"],
        ),
    },
)

def _llvm_asan_native_probe_impl(ctx):
    runtime = ctx.attr.runtime[LlvmRuntimeInfo]
    if not runtime.asan_supported or not runtime.asan_shared:
        fail("ASan native probe requires a target runtime with AddressSanitizer")
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    if runtime.target_triple != sysroot.target_triple or runtime.libc != "glibc":
        fail("ASan native probe runtime and glibc sysroot do not match")

    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    sysroot_root = sysroot.root.dirname
    library_path = ":".join([
        runtime.asan_shared.dirname,
        sysroot_root + "/lib",
        sysroot_root + "/lib64",
        sysroot_root + "/usr/lib",
        sysroot_root + "/usr/lib64",
    ])
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._asan_native_probe_runner.path,
            foreign.objdump.path,
            sysroot_root + sysroot.dynamic_linker,
            library_path,
            ctx.file.binary.path,
            marker.path,
            ctx.attr.machine,
            ctx.attr.elf_architecture,
            runtime.asan_shared_basename,
        ],
        inputs = depset(
            [ctx.file._asan_native_probe_runner, ctx.file.binary],
            transitive = [foreign.files, foreign.compiler_files, runtime.files, sysroot.files],
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
        mnemonic = "RunLlvmAsanNativeSmoke",
        progress_message = "Running AddressSanitizer behavior probe on native %s" % ctx.attr.machine,
    )
    return [
        DefaultInfo(files = depset([marker])),
        OutputGroupInfo(binary = depset([ctx.file.binary])),
    ]

_LLVM_ASAN_NATIVE_PROBE_ATTRS = {
    "binary": attr.label(allow_single_file = True, mandatory = True),
    "elf_architecture": attr.string(mandatory = True),
    "machine": attr.string(mandatory = True),
    "runtime": attr.label(mandatory = True, providers = [LlvmRuntimeInfo]),
    "_asan_native_probe_runner": attr.label(
        allow_single_file = True,
        default = "//bazel/dependencies/llvm_runtimes:run-asan-smoke.sh",
    ),
}

llvm_asan_native_probe_x86_64 = rule(
    implementation = _llvm_asan_native_probe_impl,
    attrs = _LLVM_ASAN_NATIVE_PROBE_ATTRS,
    exec_compatible_with = ["@platforms//cpu:x86_64", "@platforms//os:linux"],
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)

llvm_asan_native_probe_aarch64 = rule(
    implementation = _llvm_asan_native_probe_impl,
    attrs = _LLVM_ASAN_NATIVE_PROBE_ATTRS,
    exec_compatible_with = ["@platforms//cpu:aarch64", "@platforms//os:linux"],
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)

def _runtime_resources_1(_os, _inputs_size):
    return {"cpu": 1, "memory": 2048}

def _runtime_resources_2(_os, _inputs_size):
    return {"cpu": 2, "memory": 3072}

def _runtime_resources_4(_os, _inputs_size):
    return {"cpu": 4, "memory": 5120}

def _runtime_resources_8(_os, _inputs_size):
    return {"cpu": 8, "memory": 8192}

_RUNTIME_RESOURCES = {
    1: _runtime_resources_1,
    2: _runtime_resources_2,
    4: _runtime_resources_4,
    8: _runtime_resources_8,
}

def _llvm_target_runtime_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    if sysroot.target_triple != ctx.attr.target_triple:
        fail("resolved sysroot triple %s does not match runtime target %s" % (sysroot.target_triple, ctx.attr.target_triple))
    if sysroot.libc != ctx.attr.libc or sysroot.libc_version != ctx.attr.libc_version:
        fail("resolved sysroot %s %s does not match runtime target %s %s" % (
            sysroot.libc,
            sysroot.libc_version,
            ctx.attr.libc,
            ctx.attr.libc_version,
        ))
    if ctx.attr.jobs not in _RUNTIME_RESOURCES:
        fail("jobs must be one of 1, 2, 4, or 8 so Bazel can reserve matching CPU and memory")

    root = ctx.actions.declare_directory(ctx.label.name + ".runtime")
    resource_dir = ctx.actions.declare_directory(ctx.label.name + ".resource-dir")
    libcxx_static = ctx.actions.declare_file(ctx.label.name + ".libc++.a")
    libcxx_shared = ctx.actions.declare_file(ctx.label.name + ".libc++.so.1")
    libcxxabi_static = ctx.actions.declare_file(ctx.label.name + ".libc++abi.a")
    libcxxabi_shared = ctx.actions.declare_file(ctx.label.name + ".libc++abi.so.1")
    libunwind_static = ctx.actions.declare_file(ctx.label.name + ".libunwind.a")
    libunwind_shared = ctx.actions.declare_file(ctx.label.name + ".libunwind.so.1")
    builtins_static = ctx.actions.declare_file(ctx.label.name + ".builtins.a")
    crtbegin = ctx.actions.declare_file(ctx.label.name + ".crtbegin.o")
    crtend = ctx.actions.declare_file(ctx.label.name + ".crtend.o")
    runtime_arch = "x86_64" if ctx.attr.target_triple.startswith("x86_64-") else "aarch64"
    asan_output_dir = ctx.label.name + ".asan"
    asan_shared_basename = "libclang_rt.asan-%s.so" % runtime_arch if ctx.attr.libc == "glibc" else None
    asan_shared = ctx.actions.declare_file(asan_output_dir + "/" + asan_shared_basename) if ctx.attr.libc == "glibc" else None
    asan_static = ctx.actions.declare_file(asan_output_dir + "/libclang_rt.asan-%s.a" % runtime_arch) if ctx.attr.libc == "glibc" else None
    asan_cxx_static = ctx.actions.declare_file(asan_output_dir + "/libclang_rt.asan_cxx-%s.a" % runtime_arch) if ctx.attr.libc == "glibc" else None
    asan_preinit_static = ctx.actions.declare_file(asan_output_dir + "/libclang_rt.asan-preinit-%s.a" % runtime_arch) if ctx.attr.libc == "glibc" else None
    cxx20_smoke = ctx.actions.declare_file(ctx.label.name + ".cxx20-smoke")
    startup_crt1 = ctx.actions.declare_file(ctx.label.name + ".startup-crt1.o")
    startup_pie_crt1 = ctx.actions.declare_file(ctx.label.name + ".startup-pie-crt1.o")
    startup_crti = ctx.actions.declare_file(ctx.label.name + ".startup-crti.o")
    startup_crtn = ctx.actions.declare_file(ctx.label.name + ".startup-crtn.o")

    builtins_args = ctx.actions.args()
    builtins_args.add(ctx.file._builtins_runner.path)
    builtins_args.add_all(["--source", ctx.file.source_root.dirname])
    builtins_args.add_all(["--output", resource_dir.path])
    builtins_args.add_all(["--cmake", foreign.cmake.path])
    builtins_args.add_all(["--make", foreign.make.path])
    builtins_args.add_all(["--cc", foreign.clang.path])
    builtins_args.add_all(["--cxx", foreign.clangxx.path])
    builtins_args.add_all(["--ar", foreign.ar.path])
    builtins_args.add_all(["--ranlib", foreign.ranlib.path])
    builtins_args.add_all(["--ld", foreign.lld.path])
    builtins_args.add_all(["--nm", ctx.executable._nm.path])
    builtins_args.add_all(["--objcopy", ctx.executable._objcopy.path])
    builtins_args.add_all(["--objdump", ctx.executable._objdump.path])
    builtins_args.add_all(["--strip", ctx.executable._strip.path])
    builtins_args.add_all(["--python", ctx.executable._python.path])
    builtins_args.add_all(["--target", ctx.attr.target_triple])
    builtins_args.add_all(["--sysroot", sysroot.root.dirname])
    builtins_args.add_all(["--jobs", str(ctx.attr.jobs)])
    builtins_args.add_all(["--builtins-static", builtins_static.path])
    builtins_args.add_all(["--crtbegin", crtbegin.path])
    builtins_args.add_all(["--crtend", crtend.path])
    asan_outputs = []
    if asan_shared:
        asan_outputs = [asan_shared, asan_static, asan_cxx_static, asan_preinit_static]
    compiler_tools = [
        ctx.executable._nm,
        ctx.executable._objcopy,
        ctx.executable._objdump,
        ctx.executable._strip,
        ctx.executable._python,
    ]
    action_env = dict(
        foreign.env,
        HOME = "/nonexistent",
        LANG = "C",
        LC_ALL = "C",
        PATH = ":".join(foreign.path_entries),
        SOURCE_DATE_EPOCH = "0",
        TZ = "UTC",
        ZERO_AR_DATE = "1",
    )
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [builtins_args],
        inputs = depset(
            ctx.files.compiler_rt + [ctx.file.source_root, ctx.file._builtins_runner] + compiler_tools,
            transitive = [foreign.files, foreign.compiler_files, sysroot.files],
        ),
        outputs = [resource_dir, builtins_static, crtbegin, crtend],
        env = action_env,
        execution_requirements = {"no-network": "1"},
        mnemonic = "BuildCompilerRtTargetRuntime",
        progress_message = "Building compiler-rt 20.1.4 target runtime for %s" % ctx.attr.target_triple,
        resource_set = _RUNTIME_RESOURCES[ctx.attr.jobs],
    )

    crt_dir = sysroot.root.dirname + ("/usr/lib64" if ctx.attr.libc == "glibc" else "/usr/lib")
    startup_args = ctx.actions.args()
    startup_args.add(ctx.file._startup_runner.path)
    startup_args.add(foreign.objcopy.path)
    startup_args.add(foreign.objdump.path)
    startup_args.add_all([
        crt_dir + "/crt1.o",
        startup_crt1.path,
        crt_dir + ("/Scrt1.o" if ctx.attr.libc == "glibc" else "/crt1.o"),
        startup_pie_crt1.path,
        crt_dir + "/crti.o",
        startup_crti.path,
        crt_dir + "/crtn.o",
        startup_crtn.path,
    ])
    startup_outputs = [startup_crt1, startup_pie_crt1, startup_crti, startup_crtn]
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [startup_args],
        inputs = depset(
            [ctx.file._startup_runner],
            transitive = [foreign.files, foreign.compiler_files, sysroot.files],
        ),
        outputs = startup_outputs,
        env = action_env,
        execution_requirements = {"no-network": "1"},
        mnemonic = "PrepareTargetStartupObjects",
        progress_message = "Removing package-build DWARF from %s startup objects" % ctx.attr.target_triple,
    )

    args = ctx.actions.args()
    args.add(ctx.file._runner.path)
    args.add_all(["--source", ctx.file.source_root.dirname])
    args.add_all(["--output", root.path])
    args.add_all(["--cmake", foreign.cmake.path])
    args.add_all(["--make", foreign.make.path])
    args.add_all(["--cc", foreign.clang.path])
    args.add_all(["--cxx", foreign.clangxx.path])
    args.add_all(["--ar", foreign.ar.path])
    args.add_all(["--ranlib", foreign.ranlib.path])
    args.add_all(["--ld", foreign.lld.path])
    args.add_all(["--nm", ctx.executable._nm.path])
    args.add_all(["--objcopy", ctx.executable._objcopy.path])
    args.add_all(["--objdump", ctx.executable._objdump.path])
    args.add_all(["--strip", ctx.executable._strip.path])
    args.add_all(["--python", ctx.executable._python.path])
    args.add_all(["--target", ctx.attr.target_triple])
    args.add_all(["--libc", ctx.attr.libc])
    args.add_all(["--sysroot", sysroot.root.dirname])
    args.add_all(["--compiler-rt-root", resource_dir.path])
    args.add_all(["--jobs", str(ctx.attr.jobs)])
    args.add_all(["--libcxx-static", libcxx_static.path])
    args.add_all(["--libcxx-shared", libcxx_shared.path])
    args.add_all(["--libcxxabi-static", libcxxabi_static.path])
    args.add_all(["--libcxxabi-shared", libcxxabi_shared.path])
    args.add_all(["--libunwind-static", libunwind_static.path])
    args.add_all(["--libunwind-shared", libunwind_shared.path])
    args.add_all(["--builtins-static", builtins_static.path])
    args.add_all(["--crtbegin", crtbegin.path])
    args.add_all(["--crtend", crtend.path])
    args.add_all(["--cxx20-smoke", cxx20_smoke.path])
    if asan_shared:
        args.add_all(["--asan-shared", asan_shared.path])
        args.add_all(["--asan-static", asan_static.path])
        args.add_all(["--asan-cxx-static", asan_cxx_static.path])
        args.add_all(["--asan-preinit-static", asan_preinit_static.path])

    cxx_outputs = [
        root,
        libcxx_static,
        libcxx_shared,
        libcxxabi_static,
        libcxxabi_shared,
        libunwind_static,
        libunwind_shared,
        cxx20_smoke,
    ] + asan_outputs
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [args],
        inputs = depset(
            ctx.files.runtimes + ctx.files.compiler_rt + [
                ctx.file.source_root,
                ctx.file._runner,
                resource_dir,
                builtins_static,
                crtbegin,
                crtend,
            ] + compiler_tools,
            transitive = [foreign.files, foreign.compiler_files, sysroot.files],
        ),
        outputs = cxx_outputs,
        env = action_env,
        execution_requirements = {"no-network": "1"},
        mnemonic = "BuildLlvmTargetRuntime",
        progress_message = "Building LLVM 20.1.4 target runtime for %s" % ctx.attr.target_triple,
        resource_set = _RUNTIME_RESOURCES[ctx.attr.jobs],
    )

    files = depset(cxx_outputs + [resource_dir, builtins_static, crtbegin, crtend] + startup_outputs)
    info = LlvmRuntimeInfo(
        root = root,
        files = files,
        headers = depset([root]),
        libraries = (libcxx_static, libcxxabi_static, libunwind_static, builtins_static),
        libcxx_static = libcxx_static,
        libcxx_shared = libcxx_shared,
        libcxxabi_static = libcxxabi_static,
        libcxxabi_shared = libcxxabi_shared,
        libunwind_static = libunwind_static,
        libunwind_shared = libunwind_shared,
        builtins_static = builtins_static,
        crtbegin = crtbegin,
        crtend = crtend,
        startup_crt1 = startup_crt1,
        startup_pie_crt1 = startup_pie_crt1,
        startup_crti = startup_crti,
        startup_crtn = startup_crtn,
        resource_dir = root,
        builtins_basename = "libclang_rt.builtins-%s.a" % ("x86_64" if ctx.attr.target_triple.startswith("x86_64-") else "aarch64"),
        asan_supported = asan_shared != None,
        asan_shared = asan_shared,
        asan_static = asan_static,
        asan_cxx_static = asan_cxx_static,
        asan_preinit_static = asan_preinit_static,
        asan_shared_basename = asan_shared_basename,
        cxx20_smoke = cxx20_smoke,
        target_triple = ctx.attr.target_triple,
        libc = ctx.attr.libc,
        libc_version = ctx.attr.libc_version,
    )
    return [DefaultInfo(files = files), info]

llvm_target_runtime = rule(
    implementation = _llvm_target_runtime_impl,
    attrs = {
        "compiler_rt": attr.label(allow_files = True, mandatory = True),
        "jobs": attr.int(default = 4),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "libc_version": attr.string(mandatory = True),
        "runtimes": attr.label(allow_files = True, mandatory = True),
        "source_root": attr.label(allow_single_file = True, mandatory = True),
        "target_triple": attr.string(mandatory = True),
        "_builtins_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/llvm_runtimes:build-builtins.sh",
        ),
        "_nm": attr.label(
            cfg = "exec",
            executable = True,
            default = "//bazel/dependencies/exec_tools:llvm-nm",
        ),
        "_objcopy": attr.label(
            cfg = "exec",
            executable = True,
            default = "//bazel/dependencies/exec_tools:llvm-objcopy",
        ),
        "_objdump": attr.label(
            cfg = "exec",
            executable = True,
            default = "//bazel/dependencies/exec_tools:llvm-objdump",
        ),
        "_python": attr.label(
            cfg = "exec",
            executable = True,
            default = "//bazel/dependencies/exec_tools:python3",
        ),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/llvm_runtimes:build-runtime.sh",
        ),
        "_strip": attr.label(
            cfg = "exec",
            executable = True,
            default = "//bazel/dependencies/exec_tools:llvm-strip",
        ),
        "_startup_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/dependencies/llvm_runtimes:sanitize-startup-objects.sh",
        ),
    },
    toolchains = [
        "//bazel/toolchains:hermetic_tools_type",
        "//bazel/toolchains:sysroot_type",
    ],
)
