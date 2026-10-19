"""Execution-platform instances of the pinned LLVM 20 bindgen tool."""

load("//bazel/rust:llvm20_bindgen.bzl", "LLVM20_BINDGEN_TOOLCHAIN_TYPE", "llvm20_bindgen_tool")
load(":rust_exec_wrapper.bzl", "rust_exec_wrapper")

_EXECUTIONS = {
    "aarch64": struct(
        constraints = ["@platforms//cpu:aarch64", "@platforms//os:linux"],
        debian = "@exec_runtime_debian12_aarch64",
        exec_tools = "@exec_tools_alpine322_aarch64",
        llvm = "@llvm_20_1_4_dist_aarch64",
        lld_emulation = "aarch64linux",
        target_triple = "aarch64-alpine-linux-musl",
    ),
    "x86_64": struct(
        constraints = ["@platforms//cpu:x86_64", "@platforms//os:linux"],
        debian = "@exec_runtime_debian12_x86_64",
        exec_tools = "@exec_tools_alpine322_x86_64",
        llvm = "@llvm_20_1_4_dist_x86_64",
        lld_emulation = "elf_x86_64",
        target_triple = "x86_64-alpine-linux-musl",
    ),
}

def llvm20_bindgen_toolchains():
    for arch, execution in _EXECUTIONS.items():
        wrapper = "llvm20_bindgen_%s" % arch
        rust_exec_wrapper(
            name = wrapper,
            auto_static_exec_bin = False,
            build_runtime = [execution.exec_tools + "//:all"],
            builtins = execution.llvm + "//:lib/clang/20/lib/%s-unknown-linux-gnu/libclang_rt.builtins.a" % arch,
            clang = execution.llvm + "//:bin/clang",
            compiler_libraries = [
                execution.debian + "//:lib_anchor",
                execution.debian + "//:usr_lib_anchor",
            ],
            compiler_loader = execution.debian + "//:loader",
            compiler_runtime = [
                execution.debian + "//:all",
                execution.llvm + "//:clang",
                execution.llvm + "//:cxx_builtin_include",
                execution.llvm + "//:ld",
            ],
            crt1 = execution.exec_tools + "//:root/usr/lib/crt1.o",
            crti = execution.exec_tools + "//:root/usr/lib/crti.o",
            crtn = execution.exec_tools + "//:root/usr/lib/crtn.o",
            exec_compatible_with = execution.constraints,
            exec_loader = execution.debian + "//:loader",
            libc = execution.exec_tools + "//:root/usr/lib/libc.a",
            lld = execution.llvm + "//:bin/ld.lld",
            lld_emulation = execution.lld_emulation,
            output_basename = "bindgen",
            raw_runtime = [execution.debian + "//:all", execution.llvm + "//:libclang"],
            raw_tool = "//bazel/rust:llvm20_bindgen_raw",
            static_exec_libraries = [":stable_%s_static_exec_libraries" % arch],
            target_compatible_with = execution.constraints,
            target_triple = execution.target_triple,
        )
        implementation = wrapper + "_tool"
        llvm20_bindgen_tool(
            name = implementation,
            bindgen = ":" + wrapper,
            libclang = execution.llvm + "//:lib/libclang.so.20.1.4",
            resource_dir = execution.llvm + "//:lib/clang/20/include",
            runtime = execution.llvm + "//:libclang",
        )
        native.toolchain(
            name = wrapper + "_toolchain",
            exec_compatible_with = execution.constraints,
            target_compatible_with = ["@platforms//os:linux"],
            toolchain = ":" + implementation,
            toolchain_type = LLVM20_BINDGEN_TOOLCHAIN_TYPE,
        )
