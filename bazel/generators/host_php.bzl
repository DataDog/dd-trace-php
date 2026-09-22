"""Execution-platform PHP toolchain imported from a complete locked OCI image."""

HostPhpInfo = provider(
    doc = "Uninstrumented execution PHP and its complete declared loader closure.",
    fields = {
        "arch": "Execution architecture (amd64 or arm64).",
        "files": "depset containing PHP, loader, libraries, metadata, and optional tokenizer module.",
        "lib_root": "Marker whose dirname is the relocatable library search root.",
        "loader": "Declared ELF interpreter used to execute PHP without host runtime discovery.",
        "metadata": "Locked and validated OCI execution-runtime metadata.",
        "php": "Uninstrumented PHP CLI ELF.",
        "tokenizer": "Optional tokenizer shared module; None when built into PHP.",
        "version": "Expected PHP version validated by the native smoke action.",
    },
)

def _host_php_runtime_impl(ctx):
    tokenizer_files = ctx.attr.tokenizer[DefaultInfo].files.to_list()
    if len(tokenizer_files) > 1:
        fail("execution PHP may provide at most one tokenizer shared module")
    tokenizer = tokenizer_files[0] if tokenizer_files else None
    files = depset(
        [ctx.file.php, ctx.file.loader, ctx.file.lib_root, ctx.file.metadata] + ([tokenizer] if tokenizer else []),
        transitive = [ctx.attr.files[DefaultInfo].files],
    )
    info = HostPhpInfo(
        arch = ctx.attr.arch,
        files = files,
        lib_root = ctx.file.lib_root,
        loader = ctx.file.loader,
        metadata = ctx.file.metadata,
        php = ctx.file.php,
        tokenizer = tokenizer,
        version = ctx.attr.version,
    )
    return [DefaultInfo(files = files), platform_common.ToolchainInfo(host_php = info)]

host_php_runtime = rule(
    implementation = _host_php_runtime_impl,
    attrs = {
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "files": attr.label(mandatory = True),
        "lib_root": attr.label(allow_single_file = True, mandatory = True),
        "loader": attr.label(allow_single_file = True, mandatory = True),
        "metadata": attr.label(allow_single_file = True, mandatory = True),
        "php": attr.label(allow_single_file = True, mandatory = True),
        "tokenizer": attr.label(allow_files = True, mandatory = True),
        "version": attr.string(mandatory = True),
    },
)

def _host_php_smoke_impl(ctx):
    host = ctx.toolchains["//bazel/generators:php_generator_type"].host_php
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._runner.path,
            ctx.file._preflight.path,
            host.loader.path,
            host.lib_root.dirname,
            host.php.path,
            host.tokenizer.path if host.tokenizer else "",
            host.version,
            host.arch,
            marker.path,
        ],
        inputs = depset([ctx.file._preflight, ctx.file._runner], transitive = [host.files, foreign.files]),
        outputs = [marker],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "HostPhpNativeSmoke",
        progress_message = "Checking execution PHP on the matching native platform",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

host_php_smoke = rule(
    implementation = _host_php_smoke_impl,
    attrs = {
        "_preflight": attr.label(
            allow_single_file = True,
            default = "//bazel/generators:preflight-host-php.sh",
        ),
        "_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/generators:host-php-smoke.sh",
        ),
    },
    toolchains = [
        "//bazel/generators:php_generator_type",
        "//bazel/toolchains:hermetic_tools_type",
    ],
)
