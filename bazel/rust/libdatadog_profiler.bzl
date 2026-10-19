"""Native rules_rs targets for the reachable pinned libdatadog crates."""

load("@ddtrace_rust_crates//:defs.bzl", "crate_name", "edition")
load("@rules_cc//cc:defs.bzl", "cc_binary", "cc_library")
load(":exact_rules.bzl", "rust_library", "rust_proc_macro")
load(":product_graphs.bzl", "PRODUCT_GRAPHS")
load(":workspace_crates.bzl", "LIBDATADOG_CRATES", "rust_workspace_target_name")

_SOURCE_REPOSITORY = "@libdatadog_source//:"
_CRATE_HUB = "@ddtrace_profiler_crates//:"
_PRODUCT_DATA = PRODUCT_GRAPHS["profiler"]
_SPEC_BY_PACKAGE = {crate.package: crate for crate in LIBDATADOG_CRATES}

def _target_name(package):
    return "rust_profiler_" + package.replace("/", "_").replace("-", "_")

_TRIPLES = [
    "aarch64-unknown-linux-gnu",
    "aarch64-unknown-linux-musl",
    "x86_64-unknown-linux-gnu",
    "x86_64-unknown-linux-musl",
]
_COMMON_RUSTC_FLAGS = [
    "--cfg=tokio_unstable",
    "--check-cfg=cfg(test)",
    "--check-cfg=cfg(tokio_unstable)",
]
_BUILD_SCRIPT_DISPOSITIONS = {
    "libdatadog/datadog-sidecar-ffi": "test-only link flag omitted from production library",
    "libdatadog/libdd-common-ffi": "C header generation separated from Rust compilation",
    "libdatadog/libdd-crashtracker": "TARGET metadata and emit_sicodes C object declared below",
    "libdatadog/libdd-crashtracker-ffi": "C header generation separated from Rust compilation",
    "libdatadog/libdd-ddsketch": "generate-protobuf feature disabled; checked-in pb.rs is an input",
    "libdatadog/libdd-ipc": "glibc probe replaced by declared 2.17 target compatibility floor",
    "libdatadog/libdd-library-config-ffi": "C header generation separated from Rust compilation",
    "libdatadog/libdd-live-debugger-ffi": "C header generation separated from Rust compilation",
    "libdatadog/libdd-telemetry-ffi": "C header generation separated from Rust compilation",
    "libdatadog/libdd-trace-protobuf": "generate-protobuf feature disabled; checked-in sources are inputs",
    "libdatadog/spawn_worker": "three target C artifacts declared below",
}

def _native_dep_label(cargo_label):
    package = cargo_label.removeprefix("//")
    if package not in _SPEC_BY_PACKAGE:
        fail("unmodeled reachable libdatadog path crate: %s" % cargo_label)
    return ":" + _target_name(package)

def _dep_label(label):
    return _native_dep_label(label) if label.startswith("//") else _CRATE_HUB + label

def _conditional_values(common, by_platform, transform = None):
    result = list(common)
    branches = {}
    for platform, values in by_platform.items():
        selected = values
        if transform:
            selected = [transform(value) for value in values]
        branches[platform] = selected
    if branches:
        branches["//conditions:default"] = []
        result += select(branches)
    return result

def _selected(by_triple, transform, default = []):
    branches = {
        "@rules_rs//rs/platforms/config:%s" % triple: transform(value)
        for triple, value in by_triple.items()
    }
    branches["//conditions:default"] = default
    return select(branches)

def _transform_aliases(aliases):
    return {_dep_label(label): name for label, name in aliases.items()}

def _transform_deps(deps):
    return [_dep_label(dep) for dep in deps]

def _identity(value):
    return value

def _native_aliases(package_data):
    return _selected(package_data["aliases"], _transform_aliases, {})

def _resolved_deps(package_data):
    return _selected(package_data["deps"], _transform_deps)

def _resolved_proc_macro_deps(package_data):
    return _selected(package_data["proc_macro_deps"], _transform_deps)

def libdatadog_profiler_native_aliases(package):
    """Returns aliases for registry and remapped libdatadog path dependencies."""
    return _native_aliases(_PRODUCT_DATA[package])

def libdatadog_profiler_native_deps(package):
    """Returns the configured native targets for libdatadog path dependencies."""
    return _resolved_deps(_PRODUCT_DATA[package])

def libdatadog_profiler_native_proc_macro_deps(package):
    """Returns exact exec-configured proc-macro dependencies for a path crate."""
    return _resolved_proc_macro_deps(_PRODUCT_DATA[package])

def _crate_features(package_data):
    return _selected(package_data["features"], _identity)

def _all_resolved_features(package_data):
    result = []
    for features in package_data["features"].values():
        result.extend(features)
    return result

def _verify_disabled_generators(package, package_data):
    features = _all_resolved_features(package_data)
    if package in [
        "libdatadog/libdd-ddsketch",
        "libdatadog/libdd-trace-protobuf",
    ] and "generate-protobuf" in features:
        fail("native graph needs an explicit protobuf generator for %s" % package)
    if package == "libdatadog/libdd-crashtracker":
        for forbidden in ["cxx", "generate-unit-test-files"]:
            if forbidden in features:
                fail("native graph needs an explicit %s replacement for %s" % (forbidden, package))

def _target_env(package):
    if package == "libdatadog/spawn_worker":
        return {
            "DD_SPAWN_LD_PRELOAD_TRAMPOLINE": "$(execpath :rust_profiler_spawn_worker_ld_preload_trampoline.shared_lib)",
            "DD_SPAWN_TRAMPOLINE": "$(execpath :rust_profiler_spawn_worker_trampoline)",
        }
    if package == "libdatadog/libdd-profiling":
        # EncodedProfile::test_instance retains this path in the rlib. It is a
        # test fixture lookup, so use a stable workspace-relative value.
        return {"CARGO_MANIFEST_DIR": "libdatadog/libdd-profiling"}
    if package != "libdatadog/libdd-crashtracker":
        return {}
    branches = {
        "@rules_rs//rs/platforms/config:%s" % triple: {
            # This path is retained only by crashtracker's test helper. Keep it
            # stable and relative so production rlibs do not embed the sandbox.
            "CARGO_MANIFEST_DIR": "libdatadog/libdd-crashtracker",
            "TARGET": triple,
        }
        for triple in _TRIPLES
    }
    branches["//conditions:default"] = {
        "CARGO_MANIFEST_DIR": "libdatadog/libdd-crashtracker",
    }
    return select(branches)

def _linux_target_compatibility():
    branches = {
        "@rules_rs//rs/platforms/config:%s" % triple: []
        for triple in _TRIPLES
    }
    branches["//conditions:default"] = ["@platforms//:incompatible"]
    return select(branches)

def _rustc_flags(crate):
    flags = list(_COMMON_RUSTC_FLAGS)
    if crate.declared_features:
        flags.append("--check-cfg=cfg(feature,values(%s))" % ",".join([repr(feature) for feature in crate.declared_features]))
    if crate.package == "libdatadog/libdd-ipc":
        # The pinned GNU sysroot has a glibc 2.17 compatibility floor. Replace
        # build.rs's host glibc probe with the declared target platform.
        flags.append("--check-cfg=cfg(polyfill_glibc_memfd)")
        return flags + select({
            "@rules_rs//rs/platforms/config:aarch64-unknown-linux-gnu": ["--cfg=polyfill_glibc_memfd"],
            "@rules_rs//rs/platforms/config:x86_64-unknown-linux-gnu": ["--cfg=polyfill_glibc_memfd"],
            "//conditions:default": [],
        })
    return flags

def libdatadog_profiler_rust_targets():
    """Declares the resolved production closure of local libdatadog crates."""
    cc_library(
        name = "rust_profiler_libdatadog_crashtracker_emit_sicodes",
        srcs = ["@libdatadog_source//:libdd-crashtracker/src/crash_info/emit_sicodes.c"],
        copts = ["-g"],
    )
    cc_binary(
        name = "rust_profiler_spawn_worker_trampoline",
        srcs = ["@libdatadog_source//:spawn_worker/src/trampoline.c"],
        linkopts = [
            "-Wl,--no-as-needed",
            "-ldl",
            "-lm",
            "-lpthread",
        ],
        copts = [
            "-g",
            "-Wall",
            "-Werror",
        ],
    )
    cc_binary(
        name = "rust_profiler_spawn_worker_ld_preload_trampoline.shared_lib",
        srcs = ["@libdatadog_source//:spawn_worker/src/ld_preload_trampoline.c"],
        linkopts = ["-ldl"],
        linkshared = True,
        copts = [
            "-g",
            "-Wall",
            "-Werror",
        ],
    )
    cc_library(
        name = "rust_profiler_spawn_worker_direct_entry",
        srcs = ["@libdatadog_source//:spawn_worker/src/direct_entry.c"],
        copts = [
            "-g",
            "-Wall",
        ],
    )

    for crate in LIBDATADOG_CRATES:
        if crate.package not in _PRODUCT_DATA:
            continue
        if crate.has_build_script and crate.package not in _BUILD_SCRIPT_DISPOSITIONS:
            fail("reachable build.rs has no declared disposition: %s" % crate.package)
        package_data = _PRODUCT_DATA[crate.package]
        _verify_disabled_generators(crate.package, package_data)
        source_name = rust_workspace_target_name(crate.package)
        name = _target_name(crate.package)
        kwargs = {
            "name": name,
            "srcs": [_SOURCE_REPOSITORY + source_name + "_srcs"],
            "aliases": _native_aliases(package_data),
            "compile_data": [_SOURCE_REPOSITORY + source_name + "_data"],
            "crate_features": _crate_features(package_data),
            "crate_name": crate_name(package_name = crate.package),
            "crate_root": _SOURCE_REPOSITORY + crate.source_dir + "/" + crate.crate_root,
            "deps": _resolved_deps(package_data) + ([":rust_profiler_libdatadog_crashtracker_emit_sicodes"] if crate.package == "libdatadog/libdd-crashtracker" else []),
            "edition": edition(package_name = crate.package),
            "proc_macro_deps": _resolved_proc_macro_deps(package_data),
            "rustc_env": _target_env(crate.package),
            "rustc_flags": _rustc_flags(crate),
            "target_compatible_with": _linux_target_compatibility(),
            "version": crate.version,
        }
        if crate.package == "libdatadog/spawn_worker":
            kwargs["compile_data"] = kwargs["compile_data"] + [
                ":rust_profiler_spawn_worker_ld_preload_trampoline.shared_lib",
                ":rust_profiler_spawn_worker_trampoline",
            ]
            kwargs["deps"] = kwargs["deps"] + [":rust_profiler_spawn_worker_direct_entry"]
        if crate.package == "libdatadog/datadog-sidecar":
            # env_or_default! reads this environment in the consumer rustc
            # process while the proc macro expands each sidecar_version! call.
            kwargs["rustc_env_files"] = ["@ddtrace_rust_workspace//:sidecar_version.env"]
        if crate.proc_macro:
            rust_proc_macro(**kwargs)
        else:
            rust_library(**kwargs)
