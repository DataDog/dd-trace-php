"""Declared replacements for profiling/build.rs native and bindgen actions."""

load("@rules_cc//cc:find_cc_toolchain.bzl", "find_cc_toolchain", "use_cc_toolchain")
load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("@rules_rust//rust:rust_common.bzl", "BuildInfo")
load(":llvm20_bindgen.bzl", "llvm20_rust_bindgen")
load(":php_abi.bzl", "PhpRustAbiInfo", "php_rust_abi_config")

ProfilerBindingsInfo = provider(
    doc = "Raw and build-script-compatible profiler binding outputs.",
    fields = {
        "materialized": "Declared transformed bindings file copied into OUT_DIR and consumed by Rust.",
        "out_dir": "BuildInfo OUT_DIR tree containing the consumed bindings.rs.",
        "raw": "Raw LLVM 20 bindgen output before profiling/build.rs IntKind transformation.",
    },
)

_PROFILER_C_SRCS = [
    "ext/handlers_api.c",
    "profiling/src/php_ffi.c",
    "zend_abstract_interface/config/config.c",
    "zend_abstract_interface/config/config_decode.c",
    "zend_abstract_interface/config/config_ini.c",
    "zend_abstract_interface/config/config_runtime.c",
    "zend_abstract_interface/config/config_stable_file.c",
    "zend_abstract_interface/env/env.c",
    "zend_abstract_interface/exceptions/exceptions.c",
    "zend_abstract_interface/json/json.c",
    "zend_abstract_interface/zai_string/string.c",
]

_PROFILER_HEADERS = [
    "//:components-rs/common.h",
    "//:components-rs/library-config.h",
    "//:ext/compatibility.h",
    "//:ext/handlers_api.h",
    "//:profiling/src/php_ffi.h",
    "//:zend_abstract_interface/config/config.h",
    "//:zend_abstract_interface/config/config_decode.h",
    "//:zend_abstract_interface/config/config_ini.h",
    "//:zend_abstract_interface/config/config_stable_file.h",
    "//:zend_abstract_interface/env/env.h",
    "//:zend_abstract_interface/exceptions/exceptions.h",
    "//:zend_abstract_interface/json/json.h",
    "//:zend_abstract_interface/tsrmls_cache.h",
    "//:zend_abstract_interface/zai_assert/zai_assert.h",
    "//:zend_abstract_interface/zai_string/string.h",
    "//bazel/rust:profiler_bindings.h",
]

_BINDGEN_BLOCKLIST = [
    "FP_INFINITE",
    "FP_INT_DOWNWARD",
    "FP_INT_TONEAREST",
    "FP_INT_TONEARESTFROMZERO",
    "FP_INT_TOWARDZERO",
    "FP_INT_UPWARD",
    "FP_NAN",
    "FP_NORMAL",
    "FP_SUBNORMAL",
    "FP_ZERO",
    "IPPORT_RESERVED",
    "_zend_extension",
    "_zend_module_entry",
    "_zend_string",
    "datadog_php_profiling_vm_interrupt_addr",
    "zai_config_entry_s",
    "zai_config_memoized_entry_s",
    "zai_str",
    "zai_str_s",
    "zend_bool",
    "zend_extension",
    "zend_module_entry",
    "zend_register_extension",
    "zend_result",
    "zend_vm_opcode_handler_func_t",
    "zend_vm_opcode_handler_t",
]

def _profiler_native_ffi_impl(ctx):
    if ctx.label.package:
        fail("profiler native FFI targets must be declared in the workspace root package")
    abi = ctx.attr.abi[PhpRustAbiInfo]
    cc_toolchain = find_cc_toolchain(ctx)
    feature_configuration = cc_common.configure_features(
        ctx = ctx,
        cc_toolchain = cc_toolchain,
        requested_features = ctx.features,
        unsupported_features = ctx.disabled_features,
    )
    compilation_context, compilation_outputs = cc_common.compile(
        actions = ctx.actions,
        name = ctx.label.name,
        cc_toolchain = cc_toolchain,
        feature_configuration = feature_configuration,
        srcs = ctx.files.srcs,
        public_hdrs = ctx.files.hdrs,
        compilation_contexts = [ctx.attr.abi[CcInfo].compilation_context],
        defines = list(abi.c_defines),
        includes = [
            ".",
            "ext",
            "zend_abstract_interface",
        ],
        user_compile_flags = ["-std=gnu17"],
    )
    linking_context, linking_outputs = cc_common.create_linking_context_from_compilation_outputs(
        actions = ctx.actions,
        name = ctx.label.name,
        cc_toolchain = cc_toolchain,
        feature_configuration = feature_configuration,
        compilation_outputs = compilation_outputs,
        disallow_dynamic_library = True,
    )
    output_files = []
    if linking_outputs.library_to_link != None:
        library = linking_outputs.library_to_link
        if library.static_library != None:
            output_files.append(library.static_library)
        if library.pic_static_library != None:
            output_files.append(library.pic_static_library)
    return [
        DefaultInfo(files = depset(output_files)),
        CcInfo(
            compilation_context = compilation_context,
            linking_context = linking_context,
        ),
    ]

_profiler_native_ffi = rule(
    implementation = _profiler_native_ffi_impl,
    attrs = {
        "abi": attr.label(mandatory = True, providers = [PhpRustAbiInfo, CcInfo]),
        "hdrs": attr.label_list(allow_files = [".h"], mandatory = True),
        "srcs": attr.label_list(allow_files = [".c"], mandatory = True),
    },
    fragments = ["cpp"],
    provides = [CcInfo],
    toolchains = use_cc_toolchain(),
)

def _profiler_build_info_impl(ctx):
    abi = ctx.attr.abi[PhpRustAbiInfo]
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    out_dir = ctx.actions.declare_directory(ctx.label.name + ".out")
    materialized = ctx.actions.declare_file(ctx.label.name + ".bindings.rs")
    flags = ctx.actions.declare_file(ctx.label.name + ".rustc-flags")
    rustc_env = ctx.actions.declare_file(ctx.label.name + ".rustc-env")
    ctx.actions.write(flags, "\n".join(abi.rustc_flags) + "\n")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._materializer.path,
            ctx.file.bindings.path,
            out_dir.path,
            materialized.path,
            ctx.file.version.path,
            rustc_env.path,
        ],
        inputs = depset(
            [ctx.file._materializer, ctx.file.bindings, ctx.file.version],
            transitive = [foreign.files],
        ),
        outputs = [materialized, out_dir, rustc_env],
        env = dict(foreign.env, **{
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        mnemonic = "ProfilerBindingsMaterialize",
        progress_message = "Materializing PHP profiler bindings %{label}",
        use_default_shell_env = False,
    )
    return [
        DefaultInfo(files = depset([flags, materialized, out_dir, rustc_env])),
        BuildInfo(
            build_script_data = depset(),
            compile_data = depset([ctx.file.bindings, out_dir]),
            dep_env = None,
            flags = flags,
            linker_flags = None,
            link_search_paths = None,
            out_dir = out_dir,
            rustc_env = rustc_env,
        ),
        ProfilerBindingsInfo(
            materialized = materialized,
            out_dir = out_dir,
            raw = ctx.file.bindings,
        ),
    ]

_profiler_build_info = rule(
    implementation = _profiler_build_info_impl,
    attrs = {
        "abi": attr.label(mandatory = True, providers = [PhpRustAbiInfo]),
        "bindings": attr.label(allow_single_file = [".rs"], mandatory = True),
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_materializer": attr.label(
            default = "//bazel/rust:materialize_profiler_bindings.sh",
            allow_single_file = True,
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def profiler_native_inputs(name, php, crate_features):
    """Declares one PHP-SDK-specific profiler C/bindgen/BuildInfo closure."""
    if native.package_name():
        fail("profiler_native_inputs must be called from the workspace root package")

    abi = name + "_abi"
    ffi = name + "_ffi"
    raw_bindings = name + "_bindings_raw"
    build_info = name + "_build_info"

    php_rust_abi_config(
        name = abi,
        crate_features = crate_features,
        php = php,
    )

    _profiler_native_ffi(
        name = ffi,
        abi = ":" + abi,
        hdrs = _PROFILER_HEADERS,
        srcs = _PROFILER_C_SRCS,
    )

    llvm20_rust_bindgen(
        name = raw_bindings,
        bindgen_flags = [
            "--ctypes-prefix=libc",
            "--no-doc-comments",
            "--no-layout-tests",
            "--raw-line=extern crate libc;",
            "--raw-line=pub type zend_vm_opcode_handler_t = *const ::std::ffi::c_void;",
            "--raw-line=pub type zend_vm_opcode_handler_func_t = *const ::std::ffi::c_void;",
            "--rustified-enum=datadog_php_profiling_log_level",
            "--rustified-enum=zai_config_type",
        ] + ["--blocklist-item=" + item for item in _BINDGEN_BLOCKLIST],
        cc_lib = ":" + ffi,
        clang_flags = ["-std=gnu17"],
        header = "//bazel/rust:profiler_bindings.h",
    )

    _profiler_build_info(
        name = build_info,
        abi = ":" + abi,
        bindings = ":" + raw_bindings,
        version = "//:VERSION",
    )

    return struct(
        abi = ":" + abi,
        build_info = ":" + build_info,
        ffi = ":" + ffi,
        ffi_headers = _PROFILER_HEADERS,
    )
