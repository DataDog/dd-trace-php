"""Native C/C++ toolchains backed only by declared LLVM and sysroot inputs."""

load("@rules_cc//cc:action_names.bzl", "ACTION_NAMES")
load(
    "@rules_cc//cc:cc_toolchain_config_lib.bzl",
    "action_config",
    "env_entry",
    "env_set",
    "feature",
    "flag_group",
    "flag_set",
    "tool",
    "tool_path",
    "variable_with_value",
    "with_feature_set",
)
load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/toolchains:cc_toolchain.bzl", "cc_toolchain")
load("@rules_cc//cc/toolchains:cc_toolchain_config_info.bzl", "CcToolchainConfigInfo")
load("//bazel/dependencies/llvm_runtimes:runtime.bzl", "LlvmRuntimeInfo")
load("//bazel/toolchains:defs.bzl", "SysrootInfo")

_COMPILE_ACTIONS = [
    ACTION_NAMES.c_compile,
    ACTION_NAMES.cpp_compile,
    ACTION_NAMES.linkstamp_compile,
    ACTION_NAMES.assemble,
    ACTION_NAMES.preprocess_assemble,
    ACTION_NAMES.cpp_header_parsing,
    ACTION_NAMES.cpp_module_compile,
    ACTION_NAMES.cpp_module_codegen,
    ACTION_NAMES.cpp20_module_compile,
    ACTION_NAMES.cpp20_module_codegen,
]

_PREPROCESS_ACTIONS = [
    ACTION_NAMES.c_compile,
    ACTION_NAMES.cpp_compile,
    ACTION_NAMES.linkstamp_compile,
    ACTION_NAMES.preprocess_assemble,
    ACTION_NAMES.cpp_header_parsing,
    ACTION_NAMES.cpp_module_compile,
    ACTION_NAMES.cpp20_module_compile,
]

_CXX_PREPROCESS_ACTIONS = [
    ACTION_NAMES.cpp_compile,
    ACTION_NAMES.cpp_header_parsing,
    ACTION_NAMES.cpp_module_compile,
    ACTION_NAMES.cpp_module_codegen,
    ACTION_NAMES.cpp20_module_compile,
    ACTION_NAMES.cpp20_module_codegen,
]

_C_PREPROCESS_ACTIONS = [
    ACTION_NAMES.c_compile,
    ACTION_NAMES.linkstamp_compile,
    ACTION_NAMES.preprocess_assemble,
]

_CODEGEN_ACTIONS = [
    ACTION_NAMES.c_compile,
    ACTION_NAMES.cpp_compile,
    ACTION_NAMES.linkstamp_compile,
    ACTION_NAMES.assemble,
    ACTION_NAMES.preprocess_assemble,
    ACTION_NAMES.cpp_module_codegen,
    ACTION_NAMES.cpp20_module_codegen,
]

_LINK_ACTIONS = [
    ACTION_NAMES.cpp_link_executable,
    ACTION_NAMES.cpp_link_dynamic_library,
    ACTION_NAMES.cpp_link_nodeps_dynamic_library,
]

def _flags(name, actions, flags, enabled = True):
    return feature(
        name = name,
        enabled = enabled,
        flag_sets = [flag_set(actions = actions, flag_groups = [flag_group(flags = flags)])],
    )

def _hermetic_cc_config_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.attr.sysroot[SysrootInfo]
    runtime = ctx.attr.runtime[LlvmRuntimeInfo]
    if runtime.target_triple != sysroot.target_triple or runtime.libc != sysroot.libc:
        fail("LLVM target runtime does not match selected sysroot")
    sysroot_path = sysroot.root.dirname
    resource_include = foreign.env["HERMETIC_LLVM_ROOT"] + "/lib/clang/20/include"
    libcxx_include = runtime.header_root.path + "/include/c++/v1"
    asan_enabled = ctx.target_platform_has_constraint(ctx.attr._asan_constraint[platform_common.ConstraintValueInfo])
    if asan_enabled and not runtime.asan_supported:
        fail("ASan target platform selected a runtime without AddressSanitizer")

    # C++ tool paths are interpreted relative to this package. Generated tools
    # live below bazel-out, so address them from the action's declared execroot
    # without embedding a checkout-specific absolute path.
    execroot = "/proc/self/cwd/"
    clang_path = execroot + foreign.clang.path
    ar_path = execroot + foreign.ar.path
    lld_path = execroot + foreign.lld.path
    nm_path = execroot + foreign.nm.path
    objcopy_path = execroot + foreign.objcopy.path
    objdump_path = execroot + foreign.objdump.path
    strip_path = execroot + foreign.strip.path

    environment = feature(
        name = "hermetic_environment",
        enabled = True,
        env_sets = [env_set(
            actions = _COMPILE_ACTIONS + _LINK_ACTIONS + [ACTION_NAMES.cpp_link_static_library],
            env_entries = [
                env_entry(key = "HERMETIC_EXEC_RUNTIME_ROOT", value = foreign.env["HERMETIC_EXEC_RUNTIME_ROOT"]),
                env_entry(key = "HERMETIC_LLVM_ROOT", value = foreign.env["HERMETIC_LLVM_ROOT"]),
                env_entry(key = "HERMETIC_TOOLS_ROOT", value = foreign.env["HERMETIC_TOOLS_ROOT"]),
                env_entry(key = "LANG", value = "C"),
                env_entry(key = "LC_ALL", value = "C"),
                # The static launcher resolves its declared sibling aliases
                # (including sh for nested compiler/build-tool invocations)
                # from this single hermetic directory.
                env_entry(key = "PATH", value = foreign.shell.dirname),
            ],
        )],
    )

    compiler_input = feature(
        name = "compiler_input_flags",
        enabled = True,
        flag_sets = [flag_set(
            actions = _CODEGEN_ACTIONS,
            flag_groups = [flag_group(flags = ["-c", "%{source_file}"], expand_if_available = "source_file")],
        )],
    )
    compiler_output = feature(
        name = "compiler_output_flags",
        enabled = True,
        flag_sets = [flag_set(
            actions = _COMPILE_ACTIONS,
            flag_groups = [
                flag_group(flags = ["-S"], expand_if_available = "output_assembly_file"),
                flag_group(flags = ["-E"], expand_if_available = "output_preprocess_file"),
                flag_group(flags = ["-o", "%{output_file}"], expand_if_available = "output_file"),
            ],
        )],
    )
    dependency_file = feature(
        name = "dependency_file",
        enabled = True,
        flag_sets = [flag_set(
            actions = _COMPILE_ACTIONS,
            flag_groups = [flag_group(
                flags = ["-MD", "-MF", "%{dependency_file}"],
                expand_if_available = "dependency_file",
            )],
        )],
    )
    user_compile_flags = feature(
        name = "user_compile_flags",
        enabled = True,
        flag_sets = [flag_set(
            actions = _COMPILE_ACTIONS,
            flag_groups = [flag_group(
                flags = ["%{user_compile_flags}"],
                iterate_over = "user_compile_flags",
                expand_if_available = "user_compile_flags",
            )],
        )],
    )
    preprocessor_defines = feature(
        name = "preprocessor_defines",
        enabled = True,
        flag_sets = [flag_set(
            actions = _PREPROCESS_ACTIONS,
            flag_groups = [flag_group(flags = ["-D%{preprocessor_defines}"], iterate_over = "preprocessor_defines")],
        )],
    )
    include_paths = feature(
        name = "include_paths",
        enabled = True,
        flag_sets = [flag_set(
            actions = _PREPROCESS_ACTIONS,
            flag_groups = [
                flag_group(flags = ["-iquote", "%{quote_include_paths}"], iterate_over = "quote_include_paths"),
                flag_group(flags = ["-I%{include_paths}"], iterate_over = "include_paths"),
                flag_group(flags = ["-isystem", "%{system_include_paths}"], iterate_over = "system_include_paths"),
            ],
        )],
    )
    external_include_paths = feature(
        name = "external_include_paths",
        enabled = True,
        flag_sets = [flag_set(
            actions = _PREPROCESS_ACTIONS,
            flag_groups = [flag_group(
                flags = ["-isystem", "%{external_include_paths}"],
                iterate_over = "external_include_paths",
                expand_if_available = "external_include_paths",
            )],
        )],
    )
    forced_includes = feature(
        name = "includes",
        enabled = True,
        flag_sets = [flag_set(
            actions = _PREPROCESS_ACTIONS,
            flag_groups = [flag_group(
                flags = ["-include", "%{includes}"],
                iterate_over = "includes",
                expand_if_available = "includes",
            )],
        )],
    )
    pic = feature(
        name = "pic",
        enabled = True,
        flag_sets = [flag_set(
            actions = _CODEGEN_ACTIONS,
            flag_groups = [flag_group(flags = ["-fPIC"], expand_if_available = "pic")],
        )],
    )
    archive_flags = feature(
        name = "archiver_flags",
        flag_sets = [flag_set(
            actions = [ACTION_NAMES.cpp_link_static_library],
            flag_groups = [
                flag_group(flags = ["rcsD", "%{output_execpath}"], expand_if_available = "output_execpath"),
                flag_group(
                    iterate_over = "libraries_to_link",
                    flag_groups = [
                        flag_group(
                            flags = ["%{libraries_to_link.name}"],
                            expand_if_equal = variable_with_value(name = "libraries_to_link.type", value = "object_file"),
                        ),
                        flag_group(
                            flags = ["%{libraries_to_link.object_files}"],
                            iterate_over = "libraries_to_link.object_files",
                            expand_if_equal = variable_with_value(name = "libraries_to_link.type", value = "object_file_group"),
                        ),
                    ],
                    expand_if_available = "libraries_to_link",
                ),
            ],
        )],
    )

    runtime_link_end = _flags(
        "hermetic_runtime_link_end",
        _LINK_ACTIONS,
        [
            "-Wl,--start-group",
            runtime.libcxx_static.path,
            runtime.libcxxabi_static.path,
            runtime.libunwind_static.path,
            runtime.builtins_static.path,
            "-lc",
            "-lm",
            "-lpthread",
            "-ldl",
            "-lrt",
            "-Wl,--end-group",
            runtime.crtend.path,
            runtime.startup_crtn.path,
        ],
    )

    default_compile = _flags(
        "default_compile_flags",
        _COMPILE_ACTIONS,
        [
            "--target=" + sysroot.target_triple,
            "--sysroot=" + sysroot_path,
            "-nostdinc",
            "-fdebug-prefix-map=.=.",
            "-fdebug-compilation-dir=.",
            "-ffile-prefix-map=.=.",
            "-fmacro-prefix-map=.=.",
            "-Wno-builtin-macro-redefined",
            "-D__DATE__=\"redacted\"",
            "-D__TIME__=\"redacted\"",
            "-D__TIMESTAMP__=\"redacted\"",
        ],
    )
    cxx_compile = _flags(
        "hermetic_cxx20",
        _CXX_PREPROCESS_ACTIONS,
        [
            "-std=c++20",
            "-nostdinc++",
            "-isystem",
            libcxx_include,
            "-isystem",
            resource_include,
            "-isystem",
            sysroot_path + "/usr/include",
        ],
    )
    c_compile_headers = _flags(
        "hermetic_c_system_headers",
        _C_PREPROCESS_ACTIONS,
        ["-isystem", resource_include, "-isystem", sysroot_path + "/usr/include"],
    )
    compilation_mode_features = [
        _flags("dbg", _COMPILE_ACTIONS, ["-O0", "-g"], enabled = False),
        _flags("fastbuild", _COMPILE_ACTIONS, ["-O0", "-g"], enabled = False),
        _flags("opt", _COMPILE_ACTIONS, ["-O2", "-g", "-DNDEBUG"], enabled = False),
    ]
    asan = _flags(
        "hermetic_address_sanitizer",
        _COMPILE_ACTIONS + _LINK_ACTIONS,
        [
            "-fsanitize=address",
            "-fno-omit-frame-pointer",
        ],
        enabled = asan_enabled,
    )
    asan_link = _flags(
        "hermetic_address_sanitizer_link",
        _LINK_ACTIONS,
        ["-shared-libasan", "-resource-dir=" + runtime.resource_dir.path],
        enabled = asan_enabled,
    )

    static_library_action = action_config(
        action_name = ACTION_NAMES.cpp_link_static_library,
        tools = [tool(path = ar_path)],
        implies = ["archiver_flags"],
    )
    link_start_flags = [
        "--target=" + sysroot.target_triple,
        "--sysroot=" + sysroot_path,
        "-fuse-ld=lld",
        "--ld-path=" + lld_path,
        "-nostdlib",
        "-Wl,--dynamic-linker=" + sysroot.dynamic_linker,
        "-Wl,--build-id=sha1",
        "-Wl,--hash-style=gnu",
        "-Wl,-z,relro,-z,now",
        runtime.startup_crti.path,
        runtime.crtbegin.path,
    ]

    # Keep the executable mode and its startup object in one feature.  Old
    # glibc loaders execute a PIE's init array when ld.so is invoked directly,
    # then the executable's __libc_csu_init executes it again (BZ 16381).
    # Native validation binaries that must run through such a loader therefore
    # select the non-PIE feature and crt1.o explicitly.  Normal executables keep
    # PIE and Scrt1.o.
    pie_executable = feature(
        name = "hermetic_pie_executable",
        enabled = True,
        provides = ["hermetic_executable_startup"],
    )
    nonpie_executable = feature(
        name = "hermetic_nonpie_executable",
        provides = ["hermetic_executable_startup"],
    )
    executable_link_action = action_config(
        action_name = ACTION_NAMES.cpp_link_executable,
        flag_sets = [
            flag_set(
                flag_groups = [flag_group(flags = ["-pie", runtime.startup_pie_crt1.path] + link_start_flags)],
                with_features = [with_feature_set(features = ["hermetic_pie_executable"])],
            ),
            flag_set(
                flag_groups = [flag_group(flags = ["-no-pie", runtime.startup_crt1.path] + link_start_flags)],
                with_features = [with_feature_set(features = ["hermetic_nonpie_executable"])],
            ),
        ],
        tools = [tool(path = clang_path)],
    )
    dynamic_link_action = action_config(
        action_name = ACTION_NAMES.cpp_link_dynamic_library,
        flag_sets = [flag_set(flag_groups = [flag_group(flags = link_start_flags)])],
        tools = [tool(path = clang_path)],
    )
    nodeps_dynamic_link_action = action_config(
        action_name = ACTION_NAMES.cpp_link_nodeps_dynamic_library,
        flag_sets = [flag_set(flag_groups = [flag_group(flags = link_start_flags)])],
        tools = [tool(path = clang_path)],
    )
    features = [
        environment,
        cxx_compile,
        c_compile_headers,
        default_compile,
        dependency_file,
        compiler_input,
        compiler_output,
        user_compile_flags,
        preprocessor_defines,
        include_paths,
        external_include_paths,
        forced_includes,
        pic,
        feature(name = "supports_pic", enabled = True),
        feature(name = "supports_start_end_lib", enabled = True),
        archive_flags,
        runtime_link_end,
        pie_executable,
        nonpie_executable,
        asan,
        asan_link,
    ] + compilation_mode_features
    tool_paths = [
        tool_path(name = "ar", path = ar_path),
        tool_path(name = "cpp", path = clang_path),
        tool_path(name = "gcc", path = clang_path),
        tool_path(name = "gcov", path = clang_path),
        tool_path(name = "ld", path = lld_path),
        tool_path(name = "nm", path = nm_path),
        tool_path(name = "objcopy", path = objcopy_path),
        tool_path(name = "objdump", path = objdump_path),
        tool_path(name = "strip", path = strip_path),
    ]
    config = cc_common.create_cc_toolchain_config_info(
        ctx = ctx,
        abi_libc_version = sysroot.libc_version,
        abi_version = "llvm-20.1.4",
        action_configs = [
            static_library_action,
            executable_link_action,
            dynamic_link_action,
            nodeps_dynamic_link_action,
        ],
        builtin_sysroot = sysroot_path,
        compiler = "clang-20.1.4",
        cxx_builtin_include_directories = [
            "%workspace%/" + libcxx_include,
            "%workspace%/" + resource_include,
            "%workspace%/" + sysroot_path + "/usr/include",
        ],
        features = features,
        host_system_name = foreign.exec_triple,
        target_cpu = ctx.attr.target_cpu,
        target_libc = sysroot.libc + "-" + sysroot.libc_version,
        target_system_name = sysroot.target_triple,
        tool_paths = tool_paths,
        toolchain_identifier = "hermetic-llvm20-" + ctx.attr.target_cpu + "-" + sysroot.libc,
    )
    bootstrap = ctx.toolchains["//bazel/toolchains:bootstrap_tools_type"].bootstrap
    compile_files = depset([foreign.clang, foreign.clangxx, foreign.shell], transitive = [bootstrap.compile_files, sysroot.files, runtime.compile_inputs])
    archive_files = depset([foreign.ar, foreign.shell], transitive = [bootstrap.archive_files])
    inspection_files = depset([foreign.nm, foreign.objcopy, foreign.objdump, foreign.strip, foreign.shell], transitive = [bootstrap.inspect_files])
    link_files = depset([foreign.clang, foreign.clangxx, foreign.shell] + list(runtime.libraries), transitive = [bootstrap.link_files, sysroot.files, runtime.builtin_inputs] + ([runtime.sanitizer_inputs] if asan_enabled else []))
    files = depset(transitive = [compile_files, archive_files, inspection_files, link_files])
    return [config, DefaultInfo(files = files), OutputGroupInfo(
        compile = compile_files,
        archive = archive_files,
        inspect = inspection_files,
        link = link_files,
    )]

hermetic_cc_config = rule(
    implementation = _hermetic_cc_config_impl,
    attrs = {
        "runtime": attr.label(mandatory = True, providers = [LlvmRuntimeInfo]),
        "sysroot": attr.label(mandatory = True, providers = [SysrootInfo]),
        "target_cpu": attr.string(mandatory = True),
        "_asan_constraint": attr.label(default = "//bazel/platforms:asan"),
    },
    provides = [CcToolchainConfigInfo],
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:bootstrap_tools_type"],
)

def hermetic_cc_toolchain(name, sysroot, runtime, target_cpu, target_constraints, exec_constraints):
    """Declares one exec/target-specific native C/C++ toolchain."""
    hermetic_cc_config(
        name = name + "_config",
        runtime = runtime,
        sysroot = sysroot,
        target_cpu = target_cpu,
    )
    for group in ["compile", "archive", "inspect", "link"]:
        native.filegroup(name = name + "_" + group, srcs = [name + "_config"], output_group = group)
    cc_toolchain(
        name = name + "_impl",
        all_files = name + "_config",
        ar_files = name + "_archive",
        as_files = name + "_compile",
        compiler_files = name + "_compile",
        dwp_files = name + "_inspect",
        linker_files = name + "_link",
        objcopy_files = name + "_inspect",
        strip_files = name + "_inspect",
        supports_param_files = True,
        toolchain_config = name + "_config",
    )
    native.toolchain(
        name = name,
        exec_compatible_with = exec_constraints,
        target_compatible_with = target_constraints,
        toolchain = name + "_impl",
        toolchain_type = "@bazel_tools//tools/cpp:toolchain_type",
    )

def hermetic_cc_matrix():
    """Declares every target architecture/libc for both Linux executors."""
    executions = (
        ("amd64_exec", ["@platforms//cpu:x86_64", "@platforms//os:linux"]),
        ("arm64_exec", ["@platforms//cpu:aarch64", "@platforms//os:linux"]),
    )
    targets = (
        ("x86_64_glibc", ":centos7_x86_64", "//bazel/dependencies/llvm_runtimes:x86_64_glibc", "x86_64", ["@platforms//cpu:x86_64", "@platforms//os:linux", "//bazel/platforms:glibc"]),
        ("aarch64_glibc", ":centos7_aarch64", "//bazel/dependencies/llvm_runtimes:aarch64_glibc", "aarch64", ["@platforms//cpu:aarch64", "@platforms//os:linux", "//bazel/platforms:glibc"]),
        ("x86_64_musl", ":alpine322_x86_64", "//bazel/dependencies/llvm_runtimes:x86_64_musl", "x86_64", ["@platforms//cpu:x86_64", "@platforms//os:linux", "//bazel/platforms:musl"]),
        ("aarch64_musl", ":alpine322_aarch64", "//bazel/dependencies/llvm_runtimes:aarch64_musl", "aarch64", ["@platforms//cpu:aarch64", "@platforms//os:linux", "//bazel/platforms:musl"]),
    )
    for exec_name, exec_constraints in executions:
        for target_name, sysroot, runtime, target_cpu, target_constraints in targets:
            hermetic_cc_toolchain(
                name = "native_%s_%s" % (exec_name, target_name),
                exec_constraints = exec_constraints,
                runtime = runtime,
                sysroot = sysroot,
                target_constraints = target_constraints,
                target_cpu = target_cpu,
            )
