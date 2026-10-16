"""Repository wrapper for generated, product-specific Cargo resolver inputs."""

def _product_repository_impl(rctx):
    # rules_rs runs Cargo with the lockfile's directory as cwd. Materialize
    # both inputs so canonicalization cannot escape into the main workspace
    # and accidentally select its root Cargo.toml.
    rctx.file("Cargo.lock", rctx.read(rctx.attr.cargo_lock))
    rctx.file("Cargo.toml", rctx.read(rctx.attr.cargo_toml))
    rctx.file("src/lib.rs", "// Cargo resolution root; no compiled code.\n")
    rctx.file(
        "BUILD.bazel",
        """package(default_visibility = ["//visibility:public"])
exports_files(["Cargo.lock", "Cargo.toml"])
""",
    )
    return rctx.repo_metadata(reproducible = False)

ddtrace_rust_product_repository = repository_rule(
    implementation = _product_repository_impl,
    attrs = {
        "cargo_lock": attr.label(mandatory = True),
        "cargo_toml": attr.label(mandatory = True),
    },
    doc = "Exposes an exact generated production-feature resolver input.",
)
