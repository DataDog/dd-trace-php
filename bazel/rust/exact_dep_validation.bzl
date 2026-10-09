"""Analysis validation for Cargo-classified Rust dependency edges."""

load("@rules_rust//rust:rust_common.bzl", "CrateInfo")

def _exact_dep_classification_impl(ctx):
    for dep in ctx.attr.target_deps:
        if CrateInfo in dep and dep[CrateInfo].type == "proc-macro":
            fail("Cargo normal dependency was classified as a proc macro: %s" % dep.label)

    for dep in ctx.attr.proc_macro_deps:
        if CrateInfo not in dep:
            fail("Cargo proc-macro dependency has no CrateInfo: %s" % dep.label)
        if dep[CrateInfo].type != "proc-macro":
            fail("Cargo proc-macro dependency has crate type %s: %s" % (dep[CrateInfo].type, dep.label))

    return [DefaultInfo()]

exact_dep_classification = rule(
    implementation = _exact_dep_classification_impl,
    attrs = {
        "proc_macro_deps": attr.label_list(cfg = "exec"),
        "target_deps": attr.label_list(),
    },
    doc = "Fails analysis when generated Cargo edge kinds disagree with CrateInfo.",
)
