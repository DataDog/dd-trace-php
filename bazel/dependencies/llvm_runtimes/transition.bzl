"""Runtime configuration depends on the target ABI, never product profiles."""

# The same runtime can be reached from a product, rustc, a build script or a
# proc macro. Reset execution-transition bookkeeping as well as product flags;
# toolchain resolution still retains the selected host/execution platforms.
# Actions supply their own complete environment and never consume these flags.
_CANONICAL = {
    "//command_line_option:is exec configuration": False,
    "//command_line_option:platform_suffix": "",
    "//command_line_option:action_env": [],
    "//command_line_option:java_runtime_version": "local_jdk",
    "//command_line_option:jvmopt": [],
    "//command_line_option:compilation_mode": "opt",
    "//command_line_option:strip": "never",
    "//command_line_option:copt": [],
    "//command_line_option:conlyopt": [],
    "//command_line_option:cxxopt": [],
    "//command_line_option:linkopt": [],
    "//command_line_option:features": [],
    "@rules_rust//rust/settings:extra_rustc_flags": [],
    "@rules_rust//rust/settings:extra_exec_rustc_flags": [],
    "@rules_rust//rust/settings:lto": "unspecified",
    "@rules_rust//rust/toolchain/channel": "stable",
    "@rules_rust//rust/private:bootstrap_setting": False,
    "//bazel/rust/toolchains:nightly_proc_macro": False,
}

def _runtime_transition_impl(_settings, attr):
    arch = "arm64" if attr.target_triple.startswith("aarch64-") else "amd64"
    return dict(_CANONICAL, **{
        "//command_line_option:platforms": "//bazel/platforms:linux_%s_%s" % (arch, attr.libc),
    })

runtime_transition = transition(
    implementation = _runtime_transition_impl,
    inputs = [],
    outputs = _CANONICAL.keys() + ["//command_line_option:platforms"],
)
