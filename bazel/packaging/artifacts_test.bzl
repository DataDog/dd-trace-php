"""Analysis fixtures for the typed product artifact contract."""

load(":artifacts.bzl", "product_artifact_providers")

def _product_artifact_fixture_impl(ctx):
    binary = ctx.actions.declare_file(ctx.label.name + ".so")
    debug = ctx.actions.declare_file(ctx.label.name + ".so.debug")
    validation = ctx.actions.declare_file(ctx.label.name + ".validated")
    ctx.actions.write(binary, "binary\n")
    ctx.actions.write(debug, "debug\n")
    ctx.actions.write(validation, "validated\n")
    return product_artifact_providers(
        product = "loader",
        scope_key = "loader_amd64_glibc_none",
        artifacts = {
            "binary": binary,
            "debug": debug,
        },
        required_roles = ["binary", "debug"],
        validation = [validation],
    )

product_artifact_fixture = rule(implementation = _product_artifact_fixture_impl)
