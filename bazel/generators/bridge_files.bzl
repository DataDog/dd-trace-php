"""Explicit, host-PHP-only generation of PHP bridge preload files."""

_TOOLS_TYPE = "//bazel/toolchains:hermetic_tools_type"
_PHP_TYPE = "//bazel/generators:php_generator_type"

def _one_file(files, suffix):
    matches = [file for file in files if file.path.endswith(suffix)]
    if len(matches) != 1:
        fail("expected exactly one vendor file ending in %s, got %s" % (suffix, matches))
    return matches[0]

def _bridge_files_impl(ctx):
    host = ctx.toolchains[_PHP_TYPE].host_php
    foreign = ctx.toolchains[_TOOLS_TYPE].foreign
    vendor = ctx.files.vendor
    classpreloader = _one_file(vendor, "/classpreloader/console/bin/classpreloader")
    args = ctx.actions.args()
    args.add(ctx.file._runner.path)
    args.add(ctx.file._preflight.path)
    args.add("--php", host.php.path)
    args.add("--php-loader", host.loader.path)
    args.add("--php-library-path", host.lib_root.dirname)
    if host.tokenizer:
        args.add("--tokenizer", host.tokenizer.path)
    args.add("--classpreloader", classpreloader.path)
    args.add("--api-config", ctx.file.api_config.path)
    args.add("--tracer-config", ctx.file.tracer_config.path)
    args.add("--opentelemetry-config", ctx.file.opentelemetry_config.path)
    args.add("--openfeature-config", ctx.file.openfeature_config.path)
    args.add("--api-output", ctx.outputs.api.path)
    args.add("--tracer-output", ctx.outputs.tracer.path)
    args.add("--opentelemetry-output", ctx.outputs.opentelemetry.path)
    args.add("--openfeature-output", ctx.outputs.openfeature.path)
    env = dict(foreign.env)
    env.update({
        "HOME": "/nonexistent",
        "LANG": "C",
        "LC_ALL": "C",
        "PATH": ":".join(foreign.path_entries),
        "SOURCE_DATE_EPOCH": "0",
        "TZ": "UTC",
    })
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [args],
        inputs = depset(
            [
                classpreloader,
                ctx.file.api_config,
                ctx.file.tracer_config,
                ctx.file.opentelemetry_config,
                ctx.file.openfeature_config,
                ctx.file._preflight,
                ctx.file._runner,
            ],
            transitive = [host.files, depset(vendor), depset(ctx.files.source_files), foreign.files],
        ),
        outputs = [ctx.outputs.api, ctx.outputs.tracer, ctx.outputs.opentelemetry, ctx.outputs.openfeature],
        env = env,
        execution_requirements = {"no-network": "1"},
        mnemonic = "PhpBridgePreload",
        progress_message = "Generating PHP bridge preload files with host PHP",
    )
    return [DefaultInfo(files = depset([ctx.outputs.api, ctx.outputs.tracer, ctx.outputs.opentelemetry, ctx.outputs.openfeature]))]

bridge_files = rule(
    implementation = _bridge_files_impl,
    attrs = {
        "vendor": attr.label(mandatory = True, allow_files = True),
        "api_config": attr.label(mandatory = True, allow_single_file = True),
        "tracer_config": attr.label(mandatory = True, allow_single_file = True),
        "opentelemetry_config": attr.label(mandatory = True, allow_single_file = True),
        "openfeature_config": attr.label(mandatory = True, allow_single_file = True),
        # The config files use __DIR__ paths.  These are declared separately
        # so loading their arrays cannot read untracked sibling PHP sources.
        "source_files": attr.label_list(mandatory = True, allow_files = [".php"]),
        # The declared static shell is the executable.  Keeping this as a
        # plain file prevents a host shebang interpreter from entering the
        # action.
        "_runner": attr.label(default = "//bazel/generators:run-bridge-generator.sh", allow_single_file = True),
        "_preflight": attr.label(default = "//bazel/generators:preflight-host-php.sh", allow_single_file = True),
    },
    outputs = {
        "api": "%{name}/_generated_api.php",
        "tracer": "%{name}/_generated_tracer.php",
        "opentelemetry": "%{name}/_generated_opentelemetry.php",
        "openfeature": "%{name}/_generated_openfeature.php",
    },
    toolchains = [_TOOLS_TYPE, _PHP_TYPE],
)
