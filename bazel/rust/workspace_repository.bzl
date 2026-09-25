"""A Cargo source view assembled from the checkout and locked gitlink repos.

Cargo metadata must see path dependencies at the locations recorded in the
checked-in Cargo.toml. A fresh Git checkout intentionally has empty gitlink
directories, so pointing rules_rs directly at //:Cargo.toml would make
dependency resolution depend on whether somebody happened to run
`git submodule update`. This repository overlays the immutable gitlink source
repositories at their Cargo paths without changing the checkout or Cargo.lock.
"""

_ROOT_DIRECTORIES = (
    "components-rs",
    "profiling",
    "sidecar",
    "tracer",
    "zend_abstract_interface",
    "ext",
)

def _symlink_tree(rctx, source, destination):
    """Creates real directories with symlinked files beneath them.

    Cargo canonicalizes a symlinked member directory back into the checkout,
    which makes its relative gitlink dependencies escape this assembled
    workspace. Keeping each directory local preserves those relative paths
    while avoiding copies of ordinary source files.
    """
    frontier = [struct(source = source, destination = destination)]
    for _depth in range(64):
        next_frontier = []
        for directory in frontier:
            for entry in directory.source.readdir():
                relative = directory.destination + "/" + entry.basename
                if entry.is_dir:
                    next_frontier.append(struct(source = entry, destination = relative))
                elif entry.basename in ("Cargo.toml", "Cargo.lock"):
                    # Cargo canonicalizes manifest symlinks before resolving
                    # relative dependencies, just as it does the workspace
                    # manifest. Keep member manifests rooted here as well.
                    rctx.file(relative, rctx.read(entry))
                else:
                    rctx.symlink(entry, relative)
        frontier = next_frontier
        if not frontier:
            return
    fail("Rust workspace source tree exceeds 64 directory levels: %s" % source)

def _workspace_repository_impl(rctx):
    root = rctx.path(rctx.attr.root_cargo_toml).dirname
    libdatadog = rctx.path(rctx.attr.libdatadog_cargo_toml).dirname
    libddwaf = rctx.path(rctx.attr.libddwaf_cargo_toml).dirname

    # Repository-rule label inputs make changes to first-party member
    # manifests invalidate the Cargo resolution repository. Rust source edits
    # do not need to rerun Cargo metadata and remain ordinary action inputs.
    for manifest in rctx.attr.root_member_manifests:
        if not rctx.path(manifest).exists:
            fail("required Rust workspace manifest is missing: %s" % manifest)

    # Keep the lockfile byte-for-byte identical to the reviewed checkout.
    # Cargo canonicalizes a symlinked workspace manifest back into the main
    # checkout.  A fresh checkout has intentionally empty gitlink paths, so
    # that would bypass the immutable source repositories assembled below.
    # Materialize these small reviewed inputs to keep all relative path
    # resolution rooted in this generated repository.
    rctx.file("Cargo.toml", rctx.read(rctx.attr.root_cargo_toml))
    cargo_lock = rctx.read(rctx.attr.root_cargo_lock)
    rctx.file("Cargo.lock", cargo_lock)
    # rules_rs chooses Cargo metadata's working directory from the lock label.
    # Give the generated view a unique lock label so a module-extension cache
    # cannot alias this input back to the byte-identical main-repository lock.
    rctx.file("workspace.Cargo.lock", cargo_lock)
    rctx.file("VERSION", rctx.read(rctx.attr.root_version))

    for directory in _ROOT_DIRECTORIES:
        source = root.get_child(directory)
        if not source.exists:
            fail("required Rust workspace directory is missing: %s" % source)
        _symlink_tree(rctx, source, directory)

    # appsec also contains the libddwaf gitlink. Create its parents in this
    # repository instead of symlinking the checkout's empty gitlink directory.
    _symlink_tree(rctx, root.get_child("appsec/helper-rust"), "appsec/helper-rust")
    rctx.symlink(root.get_child("appsec/recommended.json"), "appsec/recommended.json")
    rctx.symlink(libdatadog, "libdatadog")
    rctx.symlink(libddwaf, "appsec/third_party/libddwaf-rust")

    version = rctx.read(rctx.attr.root_version).strip()
    if not version:
        fail("//:VERSION must contain the AppSec protocol version")
    if "\n" in version or "\r" in version:
        fail("//:VERSION must contain exactly one line")
    rctx.file(
        "helper_version.env",
        "\n".join([
            # rustc_env_files override rules_rust's package-based default.
            # The process wrapper expands ${pwd} to the action exec root.
            "CARGO_MANIFEST_DIR=${pwd}/appsec/helper-rust",
            "CARGO_MANIFEST_PATH=${pwd}/appsec/helper-rust/Cargo.toml",
            "DDAPPSEC_VERSION=%s" % version,
            "",
        ]),
    )
    rctx.file(
        "sidecar_version.env",
        "SIDECAR_VERSION=%s\n" % version,
    )
    rctx.file(
        "BUILD.bazel",
        """package(default_visibility = [\"//visibility:public\"])
exports_files([\"Cargo.lock\", \"Cargo.toml\", \"helper_version.env\", \"sidecar_version.env\", \"workspace.Cargo.lock\"])
""",
    )

    # The repository includes current first-party sources through symlinks;
    # Bazel still fingerprints those files when they become action inputs.
    return rctx.repo_metadata(reproducible = False)

ddtrace_rust_workspace_repository = repository_rule(
    implementation = _workspace_repository_impl,
    attrs = {
        "libdatadog_cargo_toml": attr.label(mandatory = True),
        "libddwaf_cargo_toml": attr.label(mandatory = True),
        "root_cargo_lock": attr.label(mandatory = True),
        "root_cargo_toml": attr.label(mandatory = True),
        "root_member_manifests": attr.label_list(mandatory = True),
        "root_version": attr.label(mandatory = True),
    },
    doc = "Builds the complete Cargo source view without mutating Git gitlinks.",
)
