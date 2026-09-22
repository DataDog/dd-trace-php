"""Definitions for declaring Rust compiler toolchains."""

load("@default_rust_toolchains//rustc:component_labels.bzl", "rust_toolchain_component_label")
load("@rules_rs//rs/platforms:triples.bzl", "ALL_TARGET_TRIPLES", "SUPPORTED_EXEC_TRIPLES", "SUPPORTED_TIER_3_TRIPLES")
load("@rules_rs//rs/private:bpf_linker_repository.bzl", "bpf_linker_binary_name", "bpf_linker_repository_name")
load("@rules_rs//rs/toolchains:toolchain_utils.bzl", "sanitize_triple", "sanitize_version")
load("@rules_rust//rust:rust_toolchain.bzl", "rust_toolchain")
load("@rules_rust//rust/platform:triple.bzl", _parse_triple = "triple")

def _channel(version):
    if version.startswith("nightly"):
        return "nightly"
    if version.startswith("beta"):
        return "beta"
    return "stable"

def _rustc_flags_to_select(rustc_flags_by_triple):
    return select(
        {"@rules_rs//rs/platforms/config:" + triple: flags for triple, flags in rustc_flags_by_triple.items()} |
        {"//conditions:default": []},
    )

def _component(component, triple, default):
    component = component.get(triple) if type(component) == "dict" else component
    return component or rust_toolchain_component_label(default)

def declare_rustc_toolchains(
        name,
        *,
        version,
        edition = "2021",
        rustc = None,
        exec_triples = SUPPORTED_EXEC_TRIPLES,
        target_triples = ALL_TARGET_TRIPLES,
        extra_rustc_flags = {},
        extra_exec_rustc_flags = {},
        rust_doc = None,
        rustc_lib = None,
        cargo = None,
        clippy_driver = None,
        cargo_clippy = None,
        rust_objcopy = None,
        rust_lld = None,
        bpf_linker = None,
        rust_std = None,
        extra_target_settings = [],
        source_stdlib_targets = [],
        target_compatible_with = []):
    """Declares generated or custom Rust compiler toolchains.

    Args:
      name: Target-name prefix for separately declared toolchains.
      version: Rust compiler version.
      edition: Default Rust edition; defaults to 2021.
      rustc: Optional compiler label or labels keyed by execution triple.
      exec_triples: Supported execution triples; defaults to compiler dictionary
        keys or all supported execution triples.
      target_triples: Supported target triples; defaults to all supported triples.
      extra_rustc_flags: Additional compiler flags keyed by target triple.
      extra_exec_rustc_flags: Additional compiler flags keyed by execution triple.
      rust_doc: Optional rustdoc label or labels keyed by execution triple.
      rustc_lib: Optional compiler-library label or labels keyed by execution triple.
      cargo: Optional Cargo label or labels keyed by execution triple.
      clippy_driver: Optional clippy-driver label or labels keyed by execution triple.
      cargo_clippy: Optional cargo-clippy label or labels keyed by execution triple.
      rust_objcopy: Optional rust-objcopy label or labels keyed by execution triple.
      rust_lld: Optional rust-lld label or labels keyed by execution triple.
      bpf_linker: Optional bpf-linker label or labels keyed by execution triple.
      rust_std: Optional standard-library label or labels keyed by target triple.
      extra_target_settings: Additional config_settings required by every
        generated toolchain registration.
      source_stdlib_targets: Target triples whose rust_std labels are compiled
        from source. Their bootstrap configuration receives an empty sysroot to
        prevent the precompiled core/std from leaking into the rebuilt stdlib.
      target_compatible_with: Constraints restricting target platforms for the
        declared toolchain registrations.
    """
    if type(rustc) == "dict":
        for exec_triple in rustc:
            if exec_triple not in SUPPORTED_EXEC_TRIPLES:
                fail("unsupported Rust execution triple: %s" % exec_triple)
        exec_triples = rustc.keys()

    version_key = sanitize_version(version)
    channel = _channel(version)

    rust_std_label = name + "_rust_std"
    rust_std_select = {}
    source_stdlib_select = {}
    target_triple_select = {}
    for target_triple in target_triples:
        target_key = sanitize_triple(target_triple)
        config_label = "@rules_rs//rs/platforms/config:" + target_triple
        if target_triple in SUPPORTED_TIER_3_TRIPLES or target_triple in source_stdlib_targets:
            source_stdlib_select[config_label] = "@rules_rs//rs/private:empty_stdlib"
        stdlib_repo = "rust_stdlib_%s_%s" % (target_key, version_key)
        if target_triple in SUPPORTED_TIER_3_TRIPLES:
            default_rust_std = "@rustc_src_" + version_key + "//src:rust_std"
        else:
            default_rust_std = "@%s//:rust_std-%s" % (stdlib_repo, target_triple)
        rust_std_select[config_label] = _component(rust_std, target_triple, default_rust_std)
        target_triple_select[config_label] = target_triple

    native.alias(
        name = rust_std_label,
        actual = select(rust_std_select),
    )
    toolchain_rust_std = rust_std_label
    if source_stdlib_select:
        # An alias defers triple matching until a source stdlib build.
        source_stdlib_label = name + "_source_stdlib"
        native.alias(
            name = source_stdlib_label,
            actual = select(source_stdlib_select | {"//conditions:default": rust_std_label}),
        )
        toolchain_rust_std = select({
            "@rules_rs//rs/private:source_stdlib_building_enabled": source_stdlib_label,
            "//conditions:default": rust_std_label,
        })

    for triple in exec_triples:
        exec_triple = _parse_triple(triple)
        triple_suffix = exec_triple.system + "_" + exec_triple.arch

        rustc_repo_label = "@rustc_{}_{}//:".format(triple_suffix, version_key)
        cargo_repo_label = "@cargo_{}_{}//:".format(triple_suffix, version_key)
        clippy_repo_label = "@clippy_{}_{}//:".format(triple_suffix, version_key)
        lld_label = _component(rust_lld, triple, rustc_repo_label + "rust-lld")

        rust_toolchain_name = name + "_" + triple_suffix + "_" + version_key + "_rust_toolchain"

        rust_toolchain_kwargs = dict(
            rust_doc = _component(rust_doc, triple, rustc_repo_label + "rustdoc"),
            rustc = _component(rustc, triple, rustc_repo_label + "rustc"),
            cargo = _component(cargo, triple, cargo_repo_label + "cargo"),
            clippy_driver = _component(clippy_driver, triple, clippy_repo_label + "clippy_driver_bin"),
            cargo_clippy = _component(cargo_clippy, triple, clippy_repo_label + "cargo_clippy_bin"),
            llvm_cov = "@llvm//tools:llvm-cov",
            llvm_profdata = "@llvm//tools:llvm-profdata",
            linker = select({
                "@rules_rs//rs/platforms/config:riscv32imac-unknown-none-elf": lld_label,
                "@rules_rs//rs/platforms/config:riscv32imc-unknown-none-elf": lld_label,
                "@platforms//cpu:wasm32": lld_label,
                "@platforms//cpu:wasm64": lld_label,
                "//conditions:default": None,
            }),
            linker_type = "direct",
            rust_objcopy = _component(rust_objcopy, triple, rustc_repo_label + "rust-objcopy"),
            rustc_lib = _component(rustc_lib, triple, rustc_repo_label + "rustc_lib"),
            allocator_library = None,
            global_allocator_library = None,
            binary_ext = select({
                "@rules_rs//rs/platforms/config:wasm32-unknown-emscripten": ".js",
                "@platforms//cpu:wasm32": ".wasm",
                "@platforms//cpu:wasm64": ".wasm",
                "@platforms//os:emscripten": ".js",
                "@platforms//os:uefi": ".efi",
                "@platforms//os:windows": ".exe",
                "//conditions:default": "",
            }),
            staticlib_ext = select({
                "@llvm//constraints/windows/abi:gnu": ".a",
                "@llvm//constraints/windows/abi:gnullvm": ".a",
                "@llvm//constraints/windows/abi:msvc": ".lib",
                "@platforms//os:emscripten": ".js",
                "@platforms//os:uefi": ".lib",
                "//conditions:default": ".a",
            }),
            dylib_ext = select({
                "@rules_rs//rs/platforms/config:wasm32-unknown-emscripten": ".js",
                "@platforms//cpu:wasm32": ".wasm",
                "@platforms//cpu:wasm64": ".wasm",
                "@platforms//os:android": ".so",
                "@platforms//os:emscripten": ".js",
                "@platforms//os:fuchsia": ".so",
                "@platforms//os:ios": ".dylib",
                "@platforms//os:macos": ".dylib",
                "@platforms//os:nixos": ".so",
                "@platforms//os:uefi": "",  # UEFI doesn't have dynamic linking
                "@platforms//os:windows": ".dll",
                "//conditions:default": ".so",
            }),
            stdlib_linkflags = select({
                "@platforms//os:android": ["-ldl", "-llog"],
                "@platforms//os:freebsd": ["-lexecinfo", "-lpthread"],
                "@platforms//os:macos": ["-lSystem", "-lresolv"],
                "@platforms//os:netbsd": ["-lpthread", "-lrt"],
                "@platforms//os:nixos": ["-ldl", "-lpthread"],
                "@platforms//os:openbsd": ["-lpthread"],
                "@platforms//os:ios": ["-lSystem", "-lobjc", "-Wl,-framework,Security", "-Wl,-framework,Foundation", "-lresolv"],
                "@llvm//constraints/windows/abi:gnu": ["-lws2_32", "-luserenv", "-lbcrypt", "-lntdll", "-lsynchronization"],
                "@llvm//constraints/windows/abi:gnullvm": ["-lws2_32", "-luserenv", "-lbcrypt", "-lntdll", "-lsynchronization"],
                "@llvm//constraints/windows/abi:msvc": [
                    "advapi32.lib",
                    "ws2_32.lib",
                    "userenv.lib",
                    "Bcrypt.lib",
                ],
                "//conditions:default": [],
            }),
            default_edition = edition,
            extra_exec_rustc_flags = _rustc_flags_to_select(extra_exec_rustc_flags),
            extra_rustc_flags = _rustc_flags_to_select(extra_rustc_flags),
            exec_triple = triple,
            target_triple = select(target_triple_select),
            visibility = ["//visibility:public"],
            tags = ["manual", "rust_version=" + version],
        )

        rust_toolchain(
            name = rust_toolchain_name,
            process_wrapper = "@rules_rust//util/process_wrapper",
            rust_std = toolchain_rust_std,
            **rust_toolchain_kwargs
        )

        rust_toolchain(
            name = rust_toolchain_name + "_bootstrap",
            bootstrapping = True,
            process_wrapper = "@rules_rust//util/process_wrapper:bootstrap_process_wrapper",
            rust_std = rust_std_label,
            **rust_toolchain_kwargs
        )

        bpf_linker_label = _component(bpf_linker, triple, "@%s//:%s" % (bpf_linker_repository_name(triple), bpf_linker_binary_name(triple)))
        rust_toolchain(
            name = rust_toolchain_name + "_bpf",
            linker_preference = "rust",
            process_wrapper = "@rules_rust//util/process_wrapper",
            rust_std = toolchain_rust_std,
            **(rust_toolchain_kwargs | {
                # Both branches of the former select held this same label, and
                # a select with no default cannot be analyzed for any other
                # CPU -- which breaks `bazel cquery` over the generated
                # package.  BPF targets are already gated by the toolchain's
                # target_settings.
                "linker": bpf_linker_label,
            })
        )

        for is_bpf, bootstrapping in [
            (False, False),
            (False, True),
            (True, False),
        ]:
            target_kind = "bpf" if is_bpf else "non_bpf"
            bootstrap_suffix = "_bootstrap" if bootstrapping else ""
            bootstrap_setting = "@rules_rust//rust/private:" + ("bootstrapping" if bootstrapping else "bootstrapped")
            toolchain_suffix = "_bpf" if is_bpf else bootstrap_suffix
            native.toolchain(
                name = name + "_{}_{}_to_{}_targets_{}{}".format(
                    exec_triple.system,
                    exec_triple.arch,
                    target_kind,
                    version_key,
                    bootstrap_suffix,
                ),
                exec_compatible_with = [
                    "@platforms//os:" + exec_triple.system,
                    "@platforms//cpu:" + exec_triple.arch,
                ],
                target_compatible_with = target_compatible_with,
                target_settings = [
                    "@rules_rs//rs/toolchains:bpf_targets" if is_bpf else "@rules_rs//rs/toolchains:non_bpf_targets",
                    bootstrap_setting,
                    "@rules_rust//rust/toolchain/channel:" + channel,
                ] + extra_target_settings,
                toolchain = rust_toolchain_name + toolchain_suffix,
                toolchain_type = "@rules_rust//rust:toolchain_type",
                visibility = ["//visibility:public"],
            )
