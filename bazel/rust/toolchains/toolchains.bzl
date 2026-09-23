"""Hermetic stable and ASan Rust toolchains for Linux execution platforms."""

load(
    "@default_rust_toolchains//rustc:component_labels.bzl",
    stable_component = "rust_toolchain_component_label",
)
load(
    "@rust_asan_toolchains//rustc:component_labels.bzl",
    nightly_component = "rust_toolchain_component_label",
)
load(":declare_rustc_toolchains.bzl", "declare_rustc_toolchains")
load(":rust_exec_wrapper.bzl", "rust_exec_wrapper", "rust_static_exec_libraries", "rust_target_gnu_library")

NightlyProcMacroInfo = provider(fields = {"value": "Whether proc macros require the dated nightly compiler."})

def _nightly_proc_macro_flag_impl(ctx):
    return [NightlyProcMacroInfo(value = ctx.build_setting_value)]

nightly_proc_macro_flag = rule(
    implementation = _nightly_proc_macro_flag_impl,
    build_setting = config.bool(flag = True),
)

_TARGET_TRIPLES = [
    "aarch64-unknown-linux-gnu",
    "aarch64-unknown-linux-musl",
    "x86_64-unknown-linux-gnu",
    "x86_64-unknown-linux-musl",
]

_EXECUTIONS = {
    "aarch64-unknown-linux-gnu": struct(
        arch = "aarch64",
        builtins = "@llvm_20_1_4_dist_aarch64//:lib/clang/20/lib/aarch64-unknown-linux-gnu/libclang_rt.builtins.a",
        clang = "@llvm_20_1_4_dist_aarch64//:bin/clang",
        compiler_libraries = [
            "@exec_runtime_debian12_aarch64//:lib_anchor",
            "@exec_runtime_debian12_aarch64//:usr_lib_anchor",
        ],
        compiler_loader = "@exec_runtime_debian12_aarch64//:loader",
        compiler_runtime = [
            "@exec_runtime_debian12_aarch64//:all",
            "@llvm_20_1_4_dist_aarch64//:ar",
            "@llvm_20_1_4_dist_aarch64//:clang",
            "@llvm_20_1_4_dist_aarch64//:cxx_builtin_include",
            "@llvm_20_1_4_dist_aarch64//:ld",
        ],
        constraints = ["@platforms//cpu:aarch64", "@platforms//os:linux"],
        crt1 = "@exec_tools_alpine322_aarch64//:root/usr/lib/crt1.o",
        crti = "@exec_tools_alpine322_aarch64//:root/usr/lib/crti.o",
        crtn = "@exec_tools_alpine322_aarch64//:root/usr/lib/crtn.o",
        exec_loader = "@exec_runtime_debian12_aarch64//:loader",
        libc = "@exec_tools_alpine322_aarch64//:root/usr/lib/libc.a",
        lld = "@llvm_20_1_4_dist_aarch64//:bin/ld.lld",
        lld_emulation = "aarch64linux",
        libgcc_s = "@exec_runtime_debian12_aarch64//:root/lib/aarch64-linux-gnu/libgcc_s.so.1",
        musl_runtime = ["@exec_tools_alpine322_aarch64//:all"],
        rust_repo_arch = "aarch64",
        static_exec_runtime = "//bazel/dependencies/llvm_runtimes:aarch64_glibc",
        target_triple = "aarch64-alpine-linux-musl",
    ),
    "x86_64-unknown-linux-gnu": struct(
        arch = "x86_64",
        builtins = "@llvm_20_1_4_dist_x86_64//:lib/clang/20/lib/x86_64-unknown-linux-gnu/libclang_rt.builtins.a",
        clang = "@llvm_20_1_4_dist_x86_64//:bin/clang",
        compiler_libraries = [
            "@exec_runtime_debian12_x86_64//:lib_anchor",
            "@exec_runtime_debian12_x86_64//:usr_lib_anchor",
        ],
        compiler_loader = "@exec_runtime_debian12_x86_64//:loader",
        compiler_runtime = [
            "@exec_runtime_debian12_x86_64//:all",
            "@llvm_20_1_4_dist_x86_64//:ar",
            "@llvm_20_1_4_dist_x86_64//:clang",
            "@llvm_20_1_4_dist_x86_64//:cxx_builtin_include",
            "@llvm_20_1_4_dist_x86_64//:ld",
        ],
        constraints = ["@platforms//cpu:x86_64", "@platforms//os:linux"],
        crt1 = "@exec_tools_alpine322_x86_64//:root/usr/lib/crt1.o",
        crti = "@exec_tools_alpine322_x86_64//:root/usr/lib/crti.o",
        crtn = "@exec_tools_alpine322_x86_64//:root/usr/lib/crtn.o",
        exec_loader = "@exec_runtime_debian12_x86_64//:loader",
        libc = "@exec_tools_alpine322_x86_64//:root/usr/lib/libc.a",
        lld = "@llvm_20_1_4_dist_x86_64//:bin/ld.lld",
        lld_emulation = "elf_x86_64",
        libgcc_s = "@exec_runtime_debian12_x86_64//:root/lib/x86_64-linux-gnu/libgcc_s.so.1",
        musl_runtime = ["@exec_tools_alpine322_x86_64//:all"],
        rust_repo_arch = "x86_64",
        static_exec_runtime = "//bazel/dependencies/llvm_runtimes:x86_64_glibc",
        target_triple = "x86_64-alpine-linux-musl",
    ),
}

_TOOLS = (
    struct(component = "rustc", output = "rustc", repository = "rustc", target = "rustc"),
    struct(component = "rust_doc", output = "rustdoc", repository = "rustc", target = "rustdoc"),
    struct(component = "cargo", output = "cargo", repository = "cargo", target = "cargo"),
    struct(component = "clippy_driver", output = "clippy-driver", repository = "clippy", target = "clippy_driver_bin"),
    struct(component = "cargo_clippy", output = "cargo-clippy", repository = "clippy", target = "cargo_clippy_bin"),
    struct(component = "rust_objcopy", output = "rust-objcopy", repository = "rustc", target = "rust-objcopy"),
)

def _raw_label(component_resolver, repository, execution, version_key, target):
    name = "%s_linux_%s_%s" % (repository, execution.rust_repo_arch, version_key)
    return component_resolver("@%s//:%s" % (name, target))

def _declare_version(name, version, version_key, component_resolver, target_aarch64_gnu_libraries, target_x86_64_gnu_libraries, target_compatible_with, extra_target_settings = [], source_stdlib = None):
    rustc = {}
    rust_doc = {}
    cargo = {}
    clippy_driver = {}
    cargo_clippy = {}
    rust_objcopy = {}
    rustc_lib = {}

    for exec_triple, execution in _EXECUTIONS.items():
        static_exec_libraries_name = "%s_%s_static_exec_libraries" % (name, execution.arch)
        rust_static_exec_libraries(
            name = static_exec_libraries_name,
            libgcc_s = execution.libgcc_s,
            runtime = execution.static_exec_runtime,
        )
        closure_name = "%s_%s_raw_runtime" % (name, execution.arch)
        raw_rustc_lib = _raw_label(component_resolver, "rustc", execution, version_key, "rustc_lib")
        raw_clippy_lib = _raw_label(component_resolver, "clippy", execution, version_key, "rustc_lib")
        raw_labels = [
            raw_rustc_lib,
            raw_clippy_lib,
            execution.exec_loader,
        ]
        for tool in _TOOLS:
            raw_labels.append(_raw_label(
                component_resolver,
                tool.repository,
                execution,
                version_key,
                tool.target,
            ))
        native.filegroup(
            name = closure_name,
            srcs = raw_labels + [
                "@exec_runtime_debian12_%s//:all" % execution.arch,
                ":" + static_exec_libraries_name,
            ],
        )

        component_targets = {
            "cargo": cargo,
            "cargo_clippy": cargo_clippy,
            "clippy_driver": clippy_driver,
            "rust_doc": rust_doc,
            "rust_objcopy": rust_objcopy,
            "rustc": rustc,
        }
        for tool in _TOOLS:
            wrapper_name = "%s_%s_%s" % (name, execution.arch, tool.output.replace("-", "_"))
            rust_exec_wrapper(
                name = wrapper_name,
                auto_static_exec_bin = tool.component in ["clippy_driver", "rustc"],
                build_runtime = execution.musl_runtime,
                builtins = execution.builtins,
                clang = execution.clang,
                compiler_libraries = execution.compiler_libraries,
                compiler_loader = execution.compiler_loader,
                compiler_runtime = execution.compiler_runtime,
                crt1 = execution.crt1,
                crti = execution.crti,
                crtn = execution.crtn,
                exec_compatible_with = execution.constraints,
                exec_loader = execution.exec_loader,
                libc = execution.libc,
                lld = execution.lld,
                lld_emulation = execution.lld_emulation,
                output_basename = tool.output,
                raw_runtime = [":" + closure_name],
                raw_tool = _raw_label(
                    component_resolver,
                    tool.repository,
                    execution,
                    version_key,
                    tool.target,
                ),
                static_exec_libraries = [":" + static_exec_libraries_name],
                target_compatible_with = execution.constraints,
                target_triple = execution.target_triple,
            )
            component_targets[tool.component][exec_triple] = ":" + wrapper_name
        rustc_lib[exec_triple] = ":" + closure_name

    rust_std = {}
    for target_triple in _TARGET_TRIPLES:
        if source_stdlib:
            rust_std[target_triple] = source_stdlib
        else:
            target_key = target_triple.replace("-", "_").replace(".", "_")
            repository = "rust_stdlib_%s_%s" % (target_key, version_key)
            rust_std[target_triple] = component_resolver(
                "@%s//:rust_std-%s" % (repository, target_triple),
            )

    declare_rustc_toolchains(
        name = name,
        cargo = cargo,
        cargo_clippy = cargo_clippy,
        clippy_driver = clippy_driver,
        edition = "2021",
        target_native_libraries = {
            "aarch64-unknown-linux-gnu": target_aarch64_gnu_libraries,
            "x86_64-unknown-linux-gnu": target_x86_64_gnu_libraries,
        },
        exec_triples = _EXECUTIONS.keys(),
        extra_target_settings = extra_target_settings,
        rust_doc = rust_doc,
        rust_objcopy = rust_objcopy,
        rust_std = rust_std,
        rustc = rustc,
        rustc_lib = rustc_lib,
        source_stdlib_targets = _TARGET_TRIPLES if source_stdlib else [],
        target_triples = _TARGET_TRIPLES,
        target_compatible_with = target_compatible_with,
        version = version,
    )

def _declare_stable_asan_exec_toolchains():
    """Keeps non-proc-macro ASan execution tools on stable Rust."""
    for execution in _EXECUTIONS.values():
        rust_toolchain = ":stable_linux_%s_1_87_0_rust_toolchain" % execution.arch
        for bootstrapping in [False, True]:
            suffix = "_bootstrap" if bootstrapping else ""
            native.toolchain(
                name = "stable_asan_exec_linux_%s_1_87_0%s" % (execution.arch, suffix),
                exec_compatible_with = execution.constraints,
                target_compatible_with = ["//bazel/platforms:normal"],
                target_settings = [
                    "@rules_rs//rs/toolchains:non_bpf_targets",
                    "@rules_rust//rust/private:" + ("bootstrapping" if bootstrapping else "bootstrapped"),
                    "@rules_rust//rust/toolchain/channel:nightly",
                    ":nightly_proc_macro_disabled",
                ],
                toolchain = rust_toolchain + suffix,
                toolchain_type = "@rules_rust//rust:toolchain_type",
                visibility = ["//visibility:public"],
            )

def hermetic_rust_toolchains():
    """Declares wrapped stable and dated-nightly Linux Rust toolchains."""
    rust_target_gnu_library(
        name = "target_aarch64_gnu_libraries",
        platform = "//bazel/platforms:linux_arm64_glibc",
        runtime = "//bazel/dependencies/llvm_runtimes:aarch64_glibc",
    )
    rust_target_gnu_library(
        name = "target_x86_64_gnu_libraries",
        platform = "//bazel/platforms:linux_amd64_glibc",
        runtime = "//bazel/dependencies/llvm_runtimes:x86_64_glibc",
    )
    _declare_version(
        name = "stable",
        version = "1.87.0",
        version_key = "1_87_0",
        component_resolver = stable_component,
        target_aarch64_gnu_libraries = ":target_aarch64_gnu_libraries",
        target_compatible_with = ["//bazel/platforms:normal"],
        target_x86_64_gnu_libraries = ":target_x86_64_gnu_libraries",
    )
    _declare_stable_asan_exec_toolchains()

    # A proc macro is a compiler plugin, so its metadata and proc_macro ABI
    # must match the target compiler. Rust 1.87 rejects dylibs produced by
    # nightly-2025-06-13 (and vice versa) with E0786. The proc-macro transition
    # strips product/sanitizer flags and selects this normal-platform nightly
    # toolchain with its prebuilt stdlib. Ordinary builds continue to select
    # the stable toolchain above.
    _declare_version(
        name = "nightly_exec",
        version = "nightly/2025-06-13",
        version_key = "nightly_2025_06_13",
        component_resolver = nightly_component,
        extra_target_settings = [":nightly_proc_macro_enabled"],
        target_aarch64_gnu_libraries = ":target_aarch64_gnu_libraries",
        target_compatible_with = ["//bazel/platforms:normal"],
        target_x86_64_gnu_libraries = ":target_x86_64_gnu_libraries",
    )
    _declare_version(
        name = "asan",
        version = "nightly/2025-06-13",
        version_key = "nightly_2025_06_13",
        component_resolver = nightly_component,
        target_aarch64_gnu_libraries = ":target_aarch64_gnu_libraries",
        target_compatible_with = ["//bazel/platforms:asan"],
        target_x86_64_gnu_libraries = ":target_x86_64_gnu_libraries",
        source_stdlib = "@rustc_src_nightly_2025_06_13//src:rust_std",
    )
