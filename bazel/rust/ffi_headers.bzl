"""Hermetic cbindgen actions for the checked libdatadog C FFI headers."""

_PACKAGES = {
    "": ("datadog-php", "0.0.1"),
    "datadog-sidecar": ("datadog-sidecar", "0.0.1"),
    "datadog-sidecar-ffi": ("datadog-sidecar-ffi", "0.0.1"),
    "libdd-common": ("libdd-common", "6.0.0"),
    "libdd-common-ffi": ("libdd-common-ffi", "0.0.1"),
    "libdd-crashtracker": ("libdd-crashtracker", "3.0.0"),
    "libdd-crashtracker-ffi": ("libdd-crashtracker-ffi", "0.0.1"),
    "libdd-ipc": ("libdd-ipc", "2.0.0"),
    "libdd-library-config": ("libdd-library-config", "4.0.0"),
    "libdd-library-config-ffi": ("libdd-library-config-ffi", "0.0.2"),
    "libdd-live-debugger": ("libdd-live-debugger", "1.0.0"),
    "libdd-live-debugger-ffi": ("libdd-live-debugger-ffi", "0.0.1"),
    "libdd-remote-config": ("libdd-remote-config", "5.0.0"),
    "libdd-telemetry": ("libdd-telemetry", "8.0.0"),
    "libdd-telemetry-ffi": ("libdd-telemetry-ffi", "0.0.1"),
    "@uuid": ("uuid", "1.12.1"),
}

_HEADERS = {
    "common": "libdd-common-ffi",
    "crashtracker": "libdd-crashtracker-ffi",
    "datadog": "",
    "library_config": "libdd-library-config-ffi",
    "live_debugger": "libdd-live-debugger-ffi",
    "sidecar": "datadog-sidecar-ffi",
    "telemetry": "libdd-telemetry-ffi",
}

_DEDUP_ORDER = [
    "common",
    "datadog",
    "live_debugger",
    "telemetry",
    "sidecar",
    "crashtracker",
    "library_config",
]

def _source(files, suffix):
    matches = [file for file in files if file.path == suffix or file.path.endswith("/" + suffix)]
    if len(matches) != 1:
        fail("expected one libdatadog source ending in %s, got %s" % (suffix, matches))
    return matches[0]

def _metadata(files, root_manifest):
    packages = []
    for directory, identity in sorted(_PACKAGES.items()):
        name, version = identity
        if directory == "@uuid":
            manifest = _source(files, "rules_rs++crate+ddtrace_rust_crates__uuid-1.12.1/Cargo.toml")
            crate_root = _source(files, "rules_rs++crate+ddtrace_rust_crates__uuid-1.12.1/src/lib.rs")
        elif directory:
            manifest = _source(files, directory + "/Cargo.toml")
            crate_root = _source(files, directory + "/src/lib.rs")
        else:
            manifest = root_manifest
            crate_root = _source(files, "components-rs/lib.rs")
        packages.append({
            "dependencies": [],
            "features": {},
            "id": "%s %s (path+file://%s)" % (name, version, manifest.dirname),
            "manifest_path": manifest.path,
            "name": name,
            "source": None,
            "targets": [{
                "crate_types": ["lib"],
                "kind": ["lib"],
                "name": name.replace("-", "_"),
                "src_path": crate_root.path,
            }],
            "version": version,
        })
    return json.encode({
        "packages": packages,
        "version": 1,
        "workspace_root": ".",
    })

def _ffi_header_impl(ctx):
    files = ctx.files.source_tree + ctx.files.root_source_tree
    if ctx.attr.crate_directory:
        crate_manifest = _source(files, ctx.attr.crate_directory + "/Cargo.toml")
        config = _source(files, ctx.attr.crate_directory + "/cbindgen.toml")
    else:
        crate_manifest = ctx.file.root_manifest
        config = ctx.file.root_config
    metadata = ctx.actions.declare_file(ctx.label.name + ".cargo-metadata.json")
    ctx.actions.write(metadata, _metadata(files, ctx.file.root_manifest))

    args = ctx.actions.args()
    args.add(crate_manifest.dirname)
    args.add("--config", config)
    args.add("--lockfile", ctx.file.cargo_lock)
    args.add("--metadata", metadata)
    args.add("--output", ctx.outputs.header)
    args.add("--quiet")
    ctx.actions.run(
        executable = ctx.executable.cbindgen,
        arguments = [args],
        execution_requirements = {"no-network": "1"},
        inputs = depset(direct = [ctx.file.cargo_lock, metadata], transitive = [depset(files)]),
        outputs = [ctx.outputs.header],
        mnemonic = "DatadogCbindgen",
        progress_message = "Generating %{label} with locked cbindgen 0.29.0",
        tools = [ctx.attr.cbindgen[DefaultInfo].files_to_run],
    )
    return [DefaultInfo(files = depset([ctx.outputs.header]))]

_ffi_header = rule(
    implementation = _ffi_header_impl,
    attrs = {
        "cargo_lock": attr.label(allow_single_file = ["Cargo.lock"], mandatory = True),
        "cbindgen": attr.label(cfg = "exec", executable = True, mandatory = True),
        "crate_directory": attr.string(mandatory = True),
        "root_config": attr.label(allow_single_file = [".toml"], mandatory = True),
        "root_manifest": attr.label(allow_single_file = ["Cargo.toml"], mandatory = True),
        "root_source_tree": attr.label(allow_files = True, mandatory = True),
        "source_tree": attr.label_list(allow_files = True, mandatory = True),
    },
    outputs = {"header": "%{name}.h"},
)

def _ffi_header_dedup_impl(ctx):
    outputs = {}
    ordered_outputs = []
    for name in _DEDUP_ORDER:
        output = ctx.actions.declare_file(ctx.label.name + "." + name + ".h")
        outputs[name] = depset([output])
        ordered_outputs.append(output)
    ctx.actions.run(
        executable = ctx.executable.tool,
        arguments = [file.path for file in ctx.files.headers] + [file.path for file in ordered_outputs],
        execution_requirements = {"no-network": "1"},
        inputs = ctx.files.headers,
        outputs = ordered_outputs,
        mnemonic = "DatadogFfiHeaderDedup",
        progress_message = "Deduplicating generated Datadog FFI headers",
        tools = [ctx.attr.tool[DefaultInfo].files_to_run],
    )
    return [
        DefaultInfo(files = depset(ordered_outputs)),
        OutputGroupInfo(**outputs),
    ]

_ffi_header_dedup = rule(
    implementation = _ffi_header_dedup_impl,
    attrs = {
        "headers": attr.label_list(allow_files = [".h"], mandatory = True),
        "tool": attr.label(cfg = "exec", executable = True, mandatory = True),
    },
)

def _ffi_header_check_impl(ctx):
    marker = ctx.actions.declare_file(ctx.label.name + ".ok")
    ctx.actions.run(
        executable = ctx.executable.checker,
        arguments = [ctx.file.generated.path, ctx.file.checked_in.path, marker.path],
        execution_requirements = {"no-network": "1"},
        inputs = [ctx.file.generated, ctx.file.checked_in],
        outputs = [marker],
        mnemonic = "DatadogFfiHeaderCheck",
        progress_message = "Comparing generated and checked-in header for %{label}",
        tools = [ctx.attr.checker[DefaultInfo].files_to_run],
    )
    return [DefaultInfo(files = depset([marker]))]

_ffi_header_check = rule(
    implementation = _ffi_header_check_impl,
    attrs = {
        "checked_in": attr.label(allow_single_file = [".h"], mandatory = True),
        "checker": attr.label(cfg = "exec", executable = True, mandatory = True),
        "generated": attr.label(allow_single_file = [".h"], mandatory = True),
    },
)

def ffi_header_targets():
    """Declares generation and equality gates for production FFI headers."""
    native.filegroup(
        name = "rust_ffi_root_source_tree",
        srcs = native.glob([
            "components-rs/**/*.rs",
            "profiling/**/*.rs",
            "sidecar/**/*.rs",
            "tracer/**/*.rs",
        ]) + [
            "Cargo.toml",
            "cbindgen.toml",
            "VERSION",
        ],
    )
    raw_headers = {}
    for suffix, crate_directory in _HEADERS.items():
        generated = "rust_generated_ffi_raw_" + suffix
        _ffi_header(
            name = generated,
            cargo_lock = "//:Cargo.lock",
            cbindgen = "@ddtrace_rust_crates//:cbindgen-0.29.0__cbindgen",
            crate_directory = crate_directory,
            root_config = "//:cbindgen.toml",
            root_manifest = "//:Cargo.toml",
            root_source_tree = ":rust_ffi_root_source_tree",
            source_tree = [
                "@ddtrace_rust_crates//:uuid_cbindgen_srcs",
                "@libdatadog_source//:srcs",
            ],
        )
        raw_headers[suffix] = ":" + generated

    _ffi_header_dedup(
        name = "rust_generated_ffi_headers",
        headers = [raw_headers[name] for name in _DEDUP_ORDER],
        tool = "//bazel/rust:ffi_header_dedup",
    )

    checks = []
    for suffix in _HEADERS:
        generated = "rust_generated_ffi_" + suffix
        checked = generated + "_check"
        native.filegroup(
            name = generated,
            srcs = [":rust_generated_ffi_headers"],
            output_group = suffix,
        )
        _ffi_header_check(
            name = checked,
            checked_in = "//:components-rs/%s.h" % suffix.replace("_", "-"),
            checker = "//bazel/rust:ffi_header_check",
            generated = ":" + generated,
        )
        checks.append(":" + checked)
    native.filegroup(
        name = "rust_ffi_header_checks",
        srcs = checks,
    )
