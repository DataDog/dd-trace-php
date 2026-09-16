"""Pins bootstrap/generator probes to the execution configuration."""

def _exec_probe_impl(ctx):
    return [DefaultInfo(files = ctx.attr.payload[DefaultInfo].files)]

exec_probe = rule(
    implementation = _exec_probe_impl,
    attrs = {
        "payload": attr.label(cfg = "exec", mandatory = True),
    },
    doc = "Builds a payload for execution without leaking target ABI settings.",
)
