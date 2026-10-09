"""A locked LLVM 20.1.4 bindgen execution tool and reusable binding action."""

load("@bazel_tools//tools/build_defs/cc:action_names.bzl", "C_COMPILE_ACTION_NAME")
load("@rules_cc//cc:find_cc_toolchain.bzl", "find_cc_toolchain", "use_cc_toolchain")
load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("@rules_rs//rs/private:bindgen.bzl", "CLANG_PARAMETER_FLAGS", "normalize_msvc_compile_flags")

LLVM20_BINDGEN_TOOLCHAIN_TYPE = "//bazel/rust:llvm20_bindgen_toolchain_type"

Llvm20BindgenInfo = provider(fields = {
    "bindgen": "FilesToRunProvider for the loader-wrapped bindgen driver.",
    "env": "Strict execution environment selecting the declared libclang.",
    "files": "Complete execution and LLVM resource closure.",
    "resource_dir": "Pinned LLVM 20 resource include directory.",
})

def _llvm20_bindgen_tool_impl(ctx):
    bindgen = ctx.attr.bindgen[DefaultInfo]
    files = depset(
        direct = [ctx.file.libclang, ctx.file.resource_dir],
        transitive = [
            bindgen.files,
            bindgen.default_runfiles.files,
            ctx.attr.runtime[DefaultInfo].files,
        ],
    )
    info = Llvm20BindgenInfo(
        bindgen = bindgen.files_to_run,
        env = {"LIBCLANG_PATH": ctx.file.libclang.dirname},
        files = files,
        resource_dir = ctx.file.resource_dir,
    )
    return [platform_common.ToolchainInfo(llvm20_bindgen = info)]

llvm20_bindgen_tool = rule(
    implementation = _llvm20_bindgen_tool_impl,
    attrs = {
        # Toolchain implementation targets retain the consuming target
        # configuration. Explicit exec transitions keep every executable and
        # runtime input on the platform selected for this toolchain instance.
        "bindgen": attr.label(cfg = "exec", executable = True, mandatory = True),
        "libclang": attr.label(allow_single_file = True, cfg = "exec", mandatory = True),
        "resource_dir": attr.label(allow_single_file = True, cfg = "exec", mandatory = True),
        "runtime": attr.label(cfg = "exec", mandatory = True),
    },
)

def _clang_flags(ctx, cc_toolchain, feature_configuration, resource_dir):
    compilation_context = ctx.attr.cc_lib[CcInfo].compilation_context
    clang_flags = normalize_msvc_compile_flags(ctx.attr.clang_flags)
    compile_variables = cc_common.create_compile_variables(
        cc_toolchain = cc_toolchain,
        feature_configuration = feature_configuration,
        include_directories = compilation_context.includes,
        quote_include_directories = compilation_context.quote_includes,
        system_include_directories = depset(
            direct = cc_toolchain.built_in_include_directories,
            transitive = [compilation_context.system_includes, compilation_context.external_includes],
        ),
        user_compile_flags = ctx.attr.clang_flags,
    )
    compile_flags = normalize_msvc_compile_flags(cc_common.get_memory_inefficient_command_line(
        feature_configuration = feature_configuration,
        action_name = C_COMPILE_ACTION_NAME,
        variables = compile_variables,
    ))
    allowed_flag_prefixes = (
        "-fms-runtime-lib=",
        "-no-canonical-prefixes",
        "-nostdinc",
        "-nostdinc++",
        "-nostdlibinc",
        "-std=",
        "--no-standard-includes",
    )
    xclang_flags_to_strip = (
        "-fno-cxx-modules",
        "-fexperimental-optimized-noescape",
        "-fmodule-map-file-home-is-cwd",
    )
    result = []
    copy_next = False
    skip_next = False
    for index, flag in enumerate(compile_flags):
        if skip_next:
            skip_next = False
            continue
        if copy_next:
            copy_next = False
            result.append(flag)
            continue
        if flag in clang_flags:
            result.append(flag)
            copy_next = flag in CLANG_PARAMETER_FLAGS
            continue
        if not flag.startswith(CLANG_PARAMETER_FLAGS + allowed_flag_prefixes):
            continue
        if flag == "-Xclang" and index + 1 < len(compile_flags) and compile_flags[index + 1] in xclang_flags_to_strip:
            skip_next = True
            continue
        result.append(flag)
        copy_next = flag in CLANG_PARAMETER_FLAGS

    result.append("-resource-dir=" + resource_dir.path.rsplit("/include", 1)[0])
    for define in compilation_context.defines.to_list():
        result.append("-D" + define)
    return result

def _llvm20_rust_bindgen_impl(ctx):
    header = ctx.file.header
    compilation_context = ctx.attr.cc_lib[CcInfo].compilation_context
    if header not in compilation_context.headers.to_list():
        fail("%s is not a transitive header of %s" % (ctx.attr.header.label, ctx.attr.cc_lib.label))

    tool = ctx.toolchains[LLVM20_BINDGEN_TOOLCHAIN_TYPE].llvm20_bindgen
    cc_toolchain = find_cc_toolchain(ctx)
    feature_configuration = cc_common.configure_features(
        ctx = ctx,
        cc_toolchain = cc_toolchain,
        requested_features = ctx.features,
        unsupported_features = ctx.disabled_features + ["module_maps", "use_header_modules"],
    )
    args = ctx.actions.args()
    args.add("--no-include-path-detection")
    args.add("--formatter=none")
    args.add_all(ctx.attr.bindgen_flags)
    args.add(header)
    args.add("--output", ctx.outputs.out)
    args.add("--")
    args.add_all(_clang_flags(ctx, cc_toolchain, feature_configuration, tool.resource_dir))
    ctx.actions.run(
        executable = tool.bindgen,
        arguments = [args],
        env = tool.env,
        execution_requirements = {"no-network": "1"},
        inputs = depset(transitive = [compilation_context.headers, cc_toolchain.all_files, tool.files]),
        outputs = [ctx.outputs.out],
        mnemonic = "Llvm20RustBindgen",
        progress_message = "Generating LLVM 20 Rust bindings for %{label}",
        toolchain = LLVM20_BINDGEN_TOOLCHAIN_TYPE,
    )

llvm20_rust_bindgen = rule(
    implementation = _llvm20_rust_bindgen_impl,
    attrs = {
        "bindgen_flags": attr.string_list(),
        "cc_lib": attr.label(mandatory = True, providers = [CcInfo]),
        "clang_flags": attr.string_list(),
        "header": attr.label(allow_single_file = True, mandatory = True),
    },
    fragments = ["cpp"],
    outputs = {"out": "%{name}.rs"},
    toolchains = [LLVM20_BINDGEN_TOOLCHAIN_TYPE] + use_cc_toolchain(),
)
