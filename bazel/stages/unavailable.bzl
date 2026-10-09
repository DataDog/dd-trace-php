"""Explicit failure targets for aggregate stages that are not complete yet."""

def _unavailable_stage_impl(ctx):
    fail("%s is not available: %s" % (ctx.label, ctx.attr.reason))

unavailable_stage = rule(
    implementation = _unavailable_stage_impl,
    attrs = {"reason": attr.string(mandatory = True)},
)
