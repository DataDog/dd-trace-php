"""Native loading checks for the universal Datadog PHP loader."""

_HOST_PHP_TYPE = "//bazel/generators:php_generator_type"
_TOOLS_TYPE = "//bazel/toolchains:hermetic_tools_type"

def _loader_native_smoke_impl(ctx):
    host = ctx.toolchains[_HOST_PHP_TYPE].host_php
    foreign = ctx.toolchains[_TOOLS_TYPE].foreign
    if host.arch != ctx.attr.expected_arch:
        fail("loader smoke selected %s execution PHP for %s" % (host.arch, ctx.attr.expected_arch))
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._runner.path,
            ctx.file._preflight.path,
            host.loader.path,
            host.lib_root.dirname,
            host.php.path,
            ctx.file.loader.path,
            ctx.file.version.path,
            ctx.attr.expected_arch,
            marker.path,
        ],
        inputs = depset(
            [ctx.file._preflight, ctx.file._runner, ctx.file.loader, ctx.file.version],
            transitive = [host.files, foreign.files],
        ),
        outputs = [marker],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "DatadogLoaderNativeSmoke",
        progress_message = "Loading the Datadog loader into native %s PHP" % ctx.attr.expected_arch,
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

loader_native_smoke = rule(
    implementation = _loader_native_smoke_impl,
    attrs = {
        "expected_arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "loader": attr.label(allow_single_file = True, mandatory = True),
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_preflight": attr.label(
            allow_single_file = True,
            default = "//bazel/generators:preflight-host-php.sh",
        ),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:native-smoke.sh",
        ),
    },
    toolchains = [_HOST_PHP_TYPE, _TOOLS_TYPE],
)
