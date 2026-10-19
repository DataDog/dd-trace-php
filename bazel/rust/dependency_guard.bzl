"""Analysis-time guards for forbidden production Rust dependencies."""

def _reject_aws_lc_sys_aspect_impl(target, _ctx):
    label = str(target.label)
    if "aws-lc-sys" in label:
        fail("aws-lc-sys is reachable from the production Rust target: %s" % label)
    return []

reject_aws_lc_sys_aspect = aspect(
    implementation = _reject_aws_lc_sys_aspect_impl,
    attr_aspects = [
        "actual",
        "deps",
    ],
    doc = "Fails analysis if a normal dependency edge reaches aws-lc-sys.",
)

def _rust_no_aws_lc_sys_impl(_ctx):
    return []

rust_no_aws_lc_sys = rule(
    implementation = _rust_no_aws_lc_sys_impl,
    attrs = {
        "target": attr.label(
            aspects = [reject_aws_lc_sys_aspect],
            mandatory = True,
        ),
    },
    doc = "Preserves components-rs/build.rs's production dependency guard without Cargo.",
)
