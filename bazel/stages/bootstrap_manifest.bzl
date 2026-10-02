"""Writes the declared stage-one tool pins without inspecting the host."""

def _bootstrap_manifest_impl(ctx):
    ctx.actions.write(
        ctx.outputs.out,
        "\n".join([
            "bazel=9.2.0",
            "buildbuddy_cli=5.0.468",
            "rules_rs=0.0.111",
            "rust_normal=1.87.0",
            "rust_asan=nightly-2025-06-13",
            "",
        ]),
    )
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

bootstrap_manifest = rule(
    implementation = _bootstrap_manifest_impl,
    outputs = {"out": "%{name}.txt"},
)
