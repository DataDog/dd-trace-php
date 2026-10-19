"""Native, independently cached LLVM 20 runtime compilation and link actions."""

load(":manifests/aarch64_glibc.bzl", AARCH64_GLIBC = "RUNTIME")
load(":manifests/aarch64_musl.bzl", AARCH64_MUSL = "RUNTIME")
load(":manifests/x86_64_glibc.bzl", X86_64_GLIBC = "RUNTIME")
load(":manifests/x86_64_musl.bzl", X86_64_MUSL = "RUNTIME")
load(":providers.bzl", "LlvmRuntimeInfo")
load(":transition.bzl", "runtime_transition")

_MANIFESTS = {
    "aarch64_glibc": AARCH64_GLIBC,
    "aarch64_musl": AARCH64_MUSL,
    "x86_64_glibc": X86_64_GLIBC,
    "x86_64_musl": X86_64_MUSL,
}

def _replace(value, replacements):
    for old, new in replacements.items():
        value = value.replace(old, new)
    return value

def _materialize(ctx, tools, suffix, copies, inputs):
    output = ctx.actions.declare_directory(ctx.label.name + suffix)
    manifest = ctx.actions.declare_file(ctx.label.name + suffix + ".files")
    ctx.actions.write(manifest, "".join([src + "\t" + dst + "\n" for src, dst in copies]))
    ctx.actions.run(
        executable = tools.busybox,
        arguments = ["sh", ctx.file._materialize.path, tools.busybox.path, output.path, manifest.path],
        inputs = depset([manifest, ctx.file._materialize], transitive = inputs),
        outputs = [output],
        env = tools.env,
        mnemonic = "LlvmRuntimeHeaders" if suffix == ".headers" else "LlvmRuntimeTree",
        toolchain = "//bazel/toolchains:bootstrap_tools_type",
    )
    return output

def _run_tool(ctx, tools, tool, arguments, inputs, outputs, closure, mnemonic):
    args = ctx.actions.args()
    args.add_all(["--library-path", tools.library_path, "--argv0", tool.path, tool.path])
    if tool == tools.clang or tool == tools.clangxx:
        args.add_all(["-no-canonical-prefixes", "-fintegrated-cc1"])
    args.add_all(arguments)

    ctx.actions.run(
        executable = tools.loader,
        arguments = [args],
        inputs = inputs,
        tools = closure,
        outputs = outputs,
        env = tools.env,
        execution_requirements = {"no-network": "1"},
        mnemonic = mnemonic,
        progress_message = mnemonic + " %{output}",
        toolchain = "//bazel/toolchains:bootstrap_tools_type",
    )

def _native_runtime_impl(ctx):
    tools = ctx.toolchains["//bazel/toolchains:bootstrap_tools_type"].bootstrap
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    if sysroot.target_triple != ctx.attr.target_triple or sysroot.libc != ctx.attr.libc:
        fail("runtime and resolved sysroot differ")
    manifest = _MANIFESTS[ctx.attr.variant]
    base = ctx.attr.base[LlvmRuntimeInfo] if ctx.attr.base else None
    arch = ctx.attr.target_triple.split("-")[0]
    source_root = ctx.file.source_root.dirname
    source_files = {"@SOURCE@/" + f.path[len(source_root) + 1:]: f for f in ctx.files.sources}
    component_headers = {}
    for component in ["compiler-rt", "libcxx", "libcxxabi", "libunwind", "libc"]:
        component_headers[component] = depset([f for f in ctx.files.native_headers if f.path.startswith(source_root + "/" + component + "/")])

    if base:
        header_root = base.header_root
    else:
        generated_headers = []
        copies = [
            (source_root + "/libcxx/include/.", "include/c++/v1"),
            (source_root + "/libcxxabi/include/.", "include/c++/v1"),
            (source_root + "/libunwind/include/.", "include"),
        ]
        for name, content in manifest["runtimes"]["generated"].items():
            header = ctx.actions.declare_file(ctx.label.name + ".generated/" + name)
            ctx.actions.write(header, content)
            generated_headers.append(header)
            copies.append((header.path, name))
        installed_headers = [f for f in ctx.files.native_headers if "/libcxxabi/include/" in f.path or "/libunwind/include/" in f.path]
        header_root = _materialize(ctx, tools, ".headers", copies, [depset(ctx.files.libcxx_headers + installed_headers + generated_headers)])

    artifacts = {}
    if base:
        for name, file in [("libclang_rt.builtins-%s.a" % arch, base.builtins_static), ("clang_rt.crtbegin-%s.o" % arch, base.crtbegin), ("clang_rt.crtend-%s.o" % arch, base.crtend)]:
            artifacts["builtins:@BUILD@/compiler-rt/lib/linux/" + name] = file
        for name, file in [("libc++.a", base.libcxx_static), ("libc++abi.a", base.libcxxabi_static), ("libunwind.a", base.libunwind_static), ("libc++.so.1.0", base.libcxx_shared), ("libc++abi.so.1.0", base.libcxxabi_shared), ("libunwind.so.1.0", base.libunwind_shared)]:
            artifacts["runtimes:@BUILD@/lib/" + name] = file
    compilations = []
    replacements_by_stage = {}
    duplicate_objects = {}
    for stage in (["runtimes"] if base else ["builtins", "runtimes"]):
        for name, content in manifest[stage]["generated_sources"].items():
            generated = ctx.actions.declare_file(ctx.label.name + ".generated/" + stage + "/" + name[len("@BUILD@/"):])
            ctx.actions.write(generated, content)
            source_files[name] = generated
        build_root = ctx.bin_dir.path + "/" + ctx.label.package + "/" + ctx.label.name + ".build/" + stage
        replacements = {
            "@BUILD@/include/c++/v1": header_root.path + "/include/c++/v1",
            "@BUILD@": build_root,
            "@SOURCE@": source_root,
            "@SYSROOT@": sysroot.root.dirname,
            "@RESOURCE@": tools.resource_dir,
            "@LLVM@": tools.clang.dirname + "/..",
            "@LD@": "/proc/self/cwd/" + tools.lld.path,
            "@EXECROOT@": ".",
        }
        replacements_by_stage[stage] = replacements
        for out, src, flag_index, compiler in manifest[stage]["objects"]:
            if "cxx_experimental.dir" in out:
                continue
            if stage == "runtimes" and out.startswith("@BUILD@/compiler-rt/") != bool(base):
                continue
            flags = [_replace(f, replacements) for f in manifest[stage]["flags"][flag_index]]

            # Use compiler resource headers directly; compilation never needs
            # a builtins archive, C++ library or complete installation tree.
            flags += [
                "-resource-dir=" + tools.resource_dir,
                "-fdebug-compilation-dir=.",
                "-ffile-prefix-map=" + source_root + "=llvm-project",
                "-ffile-prefix-map=" + build_root + "=llvm-runtime",
            ]
            key = (src, compiler, tuple(flags))
            if key in duplicate_objects:
                output = duplicate_objects[key]
            else:
                output = ctx.actions.declare_file(ctx.label.name + ".build/" + stage + "/" + out[len("@BUILD@/"):])
                duplicate_objects[key] = output
                compilations.append((output, src, flags, compiler))
            artifacts[stage + ":" + out] = output
        for out, _tool, _args in manifest[stage]["links"]:
            if stage == "runtimes" and out.startswith("@BUILD@/compiler-rt/") != bool(base):
                continue
            artifacts[stage + ":" + out] = ctx.actions.declare_file(ctx.label.name + ".build/" + stage + "/" + out[len("@BUILD@/"):])

    for output, src, flags, compiler in compilations:
        component = src.split("/")[1]
        headers = [component_headers[component], sysroot.files]
        direct = [source_files[src]]
        if component in ["libcxx", "libcxxabi", "compiler-rt"] and compiler == "clang++":
            direct.append(header_root)
            headers += [component_headers["libcxx"], component_headers["libcxxabi"], component_headers["libunwind"], component_headers["libc"]]
        _run_tool(
            ctx,
            tools,
            tools.clangxx if compiler == "clang++" else tools.clang,
            flags + ["-c", source_files[src].path, "-o", output.path],
            depset(direct, transitive = headers),
            [output],
            tools.compile_files,
            "LlvmRuntimeAssemble" if src.endswith(".S") else "LlvmRuntimeCompile",
        )

    builtins = artifacts["builtins:@BUILD@/compiler-rt/lib/linux/libclang_rt.builtins-%s.a" % arch]
    crtbegin = artifacts["builtins:@BUILD@/compiler-rt/lib/linux/clang_rt.crtbegin-%s.o" % arch]
    crtend = artifacts["builtins:@BUILD@/compiler-rt/lib/linux/clang_rt.crtend-%s.o" % arch]
    version_script = None
    if base:
        version_script = ctx.actions.declare_file(ctx.label.name + ".asan.vers")
        asan_archives = [artifacts["runtimes:@BUILD@/compiler-rt/lib/linux/libclang_rt.%s-%s.a" % (name, arch)] for name in ["asan", "asan_cxx"]]
        exports = [(version_script, asan_archives, ["--version-list", "--extra", ctx.file._asan_symbols.path])]
        asan_symbol_lists = []
        for archive in asan_archives:
            symbols = ctx.actions.declare_file(ctx.label.name + ".symbols/" + archive.basename + ".syms")
            asan_symbol_lists.append(symbols)
            extra = [] if "asan_cxx" in archive.basename else ["--extra", ctx.file._asan_symbols.path]
            exports.append((symbols, [archive], extra))
        for output, archives, extra in exports:
            ctx.actions.run(
                executable = ctx.executable._python,
                arguments = [ctx.file._dynamic_list.path] + extra + [
                    "--nm-executable",
                    ctx.executable._nm.path,
                    "-o",
                    output.path,
                ] + [f.path for f in archives],
                inputs = depset(
                    archives + [ctx.file._dynamic_list, ctx.file._asan_symbols, ctx.executable._nm],
                    transitive = [tools.inspect_files, depset([f for f in ctx.files._python_runtime if f.basename.startswith("python3") or "/usr/lib/python" in f.path or ".so" in f.basename])],
                ),
                outputs = [output],
                env = dict(tools.env, HERMETIC_TOOLS_ROOT = ctx.file._python_anchor.dirname + "/../..", PYTHONDONTWRITEBYTECODE = "1", PYTHONHASHSEED = "0", PYTHONNOUSERSITE = "1"),
                mnemonic = "LlvmRuntimeAsanExports",
            )

    for stage in (["runtimes"] if base else ["builtins", "runtimes"]):
        replacements = replacements_by_stage[stage]
        for out, tool, raw_args in manifest[stage]["links"]:
            if stage == "runtimes" and out.startswith("@BUILD@/compiler-rt/") != bool(base):
                continue
            output = artifacts[stage + ":" + out]
            inputs = []
            args = []
            for value in raw_args:
                artifact = artifacts.get(stage + ":" + value)
                if artifact:
                    args.append(artifact.path)
                    if artifact != output:
                        inputs.append(artifact)
                elif value.startswith("@RESOURCE@/lib/"):
                    args.append(builtins.path)
                    inputs.append(builtins)
                elif value.startswith("-Wl,--version-script,"):
                    args.append("-Wl,--version-script," + version_script.path)
                    inputs.append(version_script)
                elif value == "-rtlib=compiler-rt":
                    # Clang's implicit resource lookup would use the execution
                    # distribution's builtins. Supply this target's explicitly.
                    args.append("-rtlib=platform")
                else:
                    args.append(_replace(value, replacements))
            if tool == "llvm-ar":
                _run_tool(ctx, tools, tools.ar, args, depset(inputs), [output], tools.archive_files, "LlvmRuntimeArchive")
            else:
                args += ["-nodefaultlibs", builtins.path, "-lc"] + (["-lm"] if tool == "clang++" else [])
                inputs.append(builtins)
                _run_tool(
                    ctx,
                    tools,
                    tools.clangxx if tool == "clang++" else tools.clang,
                    args,
                    depset(inputs, transitive = [sysroot.files]),
                    [output],
                    tools.link_files,
                    "LlvmRuntimeLink",
                )

    libdir = sysroot.root.dirname + ("/usr/lib64" if ctx.attr.libc == "glibc" else "/usr/lib")
    if base:
        startup = [base.startup_crt1, base.startup_pie_crt1, base.startup_crti, base.startup_crtn]
    else:
        startup = []
        libdir = sysroot.root.dirname + ("/usr/lib64" if ctx.attr.libc == "glibc" else "/usr/lib")
        for name in ["crt1", "Scrt1", "crti", "crtn"]:
            output = ctx.actions.declare_file(ctx.label.name + ".startup/" + name + ".o")
            src = libdir + "/" + ("crt1" if ctx.attr.libc == "musl" and name == "Scrt1" else name) + ".o"
            _run_tool(ctx, tools, tools.objcopy, ["--strip-debug", src, output.path], sysroot.files, [output], tools.inspect_files, "LlvmRuntimeStartup")
            startup.append(output)

    libraries = {name: artifacts["runtimes:@BUILD@/lib/" + name] for name in ["libc++.a", "libc++abi.a", "libunwind.a", "libc++.so.1.0", "libc++abi.so.1.0", "libunwind.so.1.0"]}
    builtin_inputs = depset([builtins, crtbegin, crtend] + startup)
    link_inputs = depset(libraries.values(), transitive = [builtin_inputs])
    asan = [artifacts["runtimes:@BUILD@/compiler-rt/lib/linux/libclang_rt.%s-%s.%s" % (kind, arch, ext)] for kind, ext in [("asan", "so"), ("asan", "a"), ("asan_cxx", "a"), ("asan-preinit", "a")]] if base else []

    # Clang adds this small assembly helper even with -shared-libasan.
    asan_support = [artifacts["runtimes:@BUILD@/compiler-rt/lib/linux/libclang_rt.asan_static-%s.a" % arch]] + asan_symbol_lists if base else []
    resource_copies = [
        (tools.resource_include, "include"),
        (builtins.path, "lib/linux/" + builtins.basename),
        (crtbegin.path, "lib/linux/" + crtbegin.basename),
        (crtend.path, "lib/linux/" + crtend.basename),
    ]
    resource_copies += [(f.path, "lib/linux/" + f.basename) for f in asan + asan_support]
    resource_dir = _materialize(ctx, tools, ".resource-dir", resource_copies, [tools.resource_headers, builtin_inputs, depset(asan + asan_support)])
    install_copies = [(resource_dir.path + "/.", "."), (header_root.path + "/.", ".")]
    for name, file in libraries.items():
        install_copies.append((file.path, "lib/" + name))
        if name.endswith(".so.1.0"):
            install_copies += [(file.path, "lib/" + name[:-2]), (file.path, "lib/" + name[:-4])]
    root = _materialize(ctx, tools, ".runtime", install_copies, [depset([header_root, resource_dir]), link_inputs])

    files = depset(asan) if base else depset([header_root], transitive = [link_inputs])
    info = LlvmRuntimeInfo(
        root = root,
        header_root = header_root,
        files = depset([root], transitive = [files]),
        headers = depset([header_root]),
        compile_inputs = depset([header_root]),
        link_inputs = link_inputs,
        builtin_inputs = builtin_inputs,
        sanitizer_inputs = depset(asan + [resource_dir]) if asan else depset(),
        validation_inputs = depset(),
        libraries = tuple([libraries[n] for n in ["libc++.a", "libc++abi.a", "libunwind.a"]] + [builtins]),
        libcxx_static = libraries["libc++.a"],
        libcxx_shared = libraries["libc++.so.1.0"],
        libcxxabi_static = libraries["libc++abi.a"],
        libcxxabi_shared = libraries["libc++abi.so.1.0"],
        libunwind_static = libraries["libunwind.a"],
        libunwind_shared = libraries["libunwind.so.1.0"],
        builtins_static = builtins,
        crtbegin = crtbegin,
        crtend = crtend,
        startup_crt1 = startup[0],
        startup_pie_crt1 = startup[1],
        startup_crti = startup[2],
        startup_crtn = startup[3],
        resource_dir = resource_dir,
        builtins_basename = builtins.basename,
        asan_supported = bool(asan),
        asan_shared = asan[0] if asan else None,
        asan_static = asan[1] if asan else None,
        asan_cxx_static = asan[2] if asan else None,
        asan_preinit_static = asan[3] if asan else None,
        asan_shared_basename = asan[0].basename if asan else None,
        cxx20_smoke = None,
        target_triple = ctx.attr.target_triple,
        libc = ctx.attr.libc,
        libc_version = sysroot.libc_version,
    )
    return [DefaultInfo(files = files), info, OutputGroupInfo(
        headers = info.headers,
        builtins = depset([builtins]),
        crt = depset([crtbegin, crtend] + startup),
        libunwind = depset([libraries["libunwind.a"], libraries["libunwind.so.1.0"]]),
        libcxxabi = depset([libraries["libc++abi.a"], libraries["libc++abi.so.1.0"]]),
        libcxx = depset([libraries["libc++.a"], libraries["libc++.so.1.0"]]),
        libraries = link_inputs,
        asan = info.sanitizer_inputs,
        validation = info.validation_inputs,
        install = depset([root]),
    )]

_native_runtime = rule(
    implementation = _native_runtime_impl,
    cfg = runtime_transition,
    attrs = {
        "variant": attr.string(mandatory = True),
        "base": attr.label(providers = [LlvmRuntimeInfo]),
        "target_triple": attr.string(mandatory = True),
        "libc": attr.string(mandatory = True),
        "sources": attr.label_list(allow_files = True),
        "native_headers": attr.label(default = "@llvm_runtime_sources_20_1_4//:native_headers"),
        "libcxx_headers": attr.label(default = "@llvm_runtime_sources_20_1_4//:libcxx_headers"),
        "source_root": attr.label(allow_single_file = True, default = "@llvm_runtime_sources_20_1_4//:root"),
        "_materialize": attr.label(allow_single_file = True, default = ":materialize.sh"),
        "_dynamic_list": attr.label(allow_single_file = True, default = "@llvm_runtime_sources_20_1_4//:compiler-rt/lib/sanitizer_common/scripts/gen_dynamic_list.py"),
        "_asan_symbols": attr.label(allow_single_file = True, default = "@llvm_runtime_sources_20_1_4//:compiler-rt/lib/asan/asan.syms.extra"),
        "_python": attr.label(executable = True, cfg = "exec", default = "//bazel/dependencies/exec_tools:python3"),
        "_nm": attr.label(executable = True, cfg = "exec", default = "//bazel/dependencies/exec_tools:llvm-nm"),
        "_python_runtime": attr.label(cfg = "exec", default = "//bazel/dependencies/exec_tools:python_runtime"),
        "_python_anchor": attr.label(allow_single_file = True, cfg = "exec", default = "//bazel/dependencies/exec_tools:python_anchor"),
        "_allowlist_function_transition": attr.label(default = "@bazel_tools//tools/allowlists/function_transition_allowlist"),
    },
    toolchains = ["//bazel/toolchains:bootstrap_tools_type", "//bazel/toolchains:sysroot_type"],
)

def _runtime_validation_impl(ctx):
    runtime = ctx.attr.runtime[LlvmRuntimeInfo]
    tools = ctx.toolchains["//bazel/toolchains:bootstrap_tools_type"].bootstrap
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    libdir = sysroot.root.dirname + ("/usr/lib64" if runtime.libc == "glibc" else "/usr/lib")
    smoke = ctx.actions.declare_file(ctx.label.name + ".cxx20-smoke")
    smoke_obj = ctx.actions.declare_file(ctx.label.name + ".cxx20-smoke.o")
    _run_tool(
        ctx,
        tools,
        tools.clangxx,
        [
            "--target=" + runtime.target_triple,
            "--sysroot=" + sysroot.root.dirname,
            "-resource-dir=" + tools.resource_dir,
            "-std=c++20",
            "-nostdinc++",
            "-isystem",
            runtime.header_root.path + "/include/c++/v1",
            "-c",
            ctx.file._smoke.path,
            "-o",
            smoke_obj.path,
        ],
        depset([ctx.file._smoke], transitive = [runtime.headers, sysroot.files]),
        [smoke_obj],
        tools.compile_files,
        "LlvmRuntimeSmokeCompile",
    )
    _run_tool(
        ctx,
        tools,
        tools.clangxx,
        [
            "--target=" + runtime.target_triple,
            "--sysroot=" + sysroot.root.dirname,
            "--ld-path=/proc/self/cwd/" + tools.lld.path,
            "-nostdlib",
            "-static",
            "-L" + libdir,
            "-Wl,--build-id=sha1",
            "-o",
            smoke.path,
            runtime.startup_crt1.path,
            runtime.startup_crti.path,
            runtime.crtbegin.path,
            smoke_obj.path,
            "-Wl,--start-group",
        ] +
        [f.path for f in runtime.libraries] +
        ["-lpthread", "-ldl", "-lrt", "-lc", "-lm", "-Wl,--end-group", runtime.crtend.path, runtime.startup_crtn.path],
        depset([smoke_obj], transitive = [runtime.link_inputs, sysroot.files]),
        [smoke],
        tools.link_files,
        "LlvmRuntimeSmokeLink",
    )
    fields = {field: getattr(runtime, field) for field in dir(runtime)}
    fields.update(cxx20_smoke = smoke, validation_inputs = depset([smoke]))
    return [DefaultInfo(files = depset([smoke])), LlvmRuntimeInfo(**fields), OutputGroupInfo(validation = depset([smoke]))]

_runtime_validation = rule(
    implementation = _runtime_validation_impl,
    cfg = runtime_transition,
    attrs = {
        "runtime": attr.label(providers = [LlvmRuntimeInfo], mandatory = True),
        "target_triple": attr.string(mandatory = True),
        "libc": attr.string(mandatory = True),
        "_smoke": attr.label(allow_single_file = True, default = ":cxx20-smoke.cc"),
        "_allowlist_function_transition": attr.label(default = "@bazel_tools//tools/allowlists/function_transition_allowlist"),
    },
    toolchains = ["//bazel/toolchains:bootstrap_tools_type", "//bazel/toolchains:sysroot_type"],
)

def native_llvm_target_runtime(name, variant, target_triple, libc, **kwargs):
    manifest = _MANIFESTS[variant]
    sources = sorted({src: True for stage in ["builtins", "runtimes"] for _out, src, _flags, _compiler in manifest[stage]["objects"]})
    attrs = dict(
        variant = variant,
        target_triple = target_triple,
        libc = libc,
        sources = [src.replace("@SOURCE@/", "@llvm_runtime_sources_20_1_4//:") for src in sources if src.startswith("@SOURCE@/")],
        **kwargs
    )
    _native_runtime(name = name + "_core", **attrs)
    _runtime_validation(name = name + "_validation", runtime = ":" + name + "_core", target_triple = target_triple, libc = libc)
    for component in ["headers", "builtins", "crt", "libunwind", "libcxxabi", "libcxx"]:
        native.filegroup(name = name + "_" + component, srcs = [":" + name + "_core"], output_group = component)
    if libc == "glibc":
        _native_runtime(name = name + "_asan", base = ":" + name + "_core", **attrs)
    native.alias(
        name = name,
        actual = select({
            "//bazel/platforms:asan": ":" + name + "_asan",
            "//conditions:default": ":" + name + "_core",
        }) if libc == "glibc" else ":" + name + "_core",
    )
