"""Deterministic debug splitting and ELF checks for the PHP loader."""

load("//bazel/php:php_toolchain.bzl", "PhpToolchainInfo")

def _loader_metadata_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    php = ctx.attr.php[PhpToolchainInfo]
    output = ctx.actions.declare_file(ctx.label.name + ".json")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._writer.path,
            ctx.file.version.path,
            output.path,
            php.target_arch,
            php.source_image_digest,
            php.target_libc,
            str(php.api),
            php.configuration_name,
            php.version,
        ],
        inputs = depset([ctx.file._writer, ctx.file.version], transitive = [foreign.files]),
        outputs = [output],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "WritePhpLoaderMetadata",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([output]))]

loader_metadata = rule(
    implementation = _loader_metadata_impl,
    attrs = {
        "php": attr.label(mandatory = True, providers = [PhpToolchainInfo]),
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_writer": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:write-loader-metadata.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _loader_ini_impl(ctx):
    output = ctx.actions.declare_file(ctx.label.name + ".ini")
    ctx.actions.write(
        output,
        "zend_extension=${DD_LOADER_PACKAGE_PATH}/%s/loader/dd_library_loader.so\n" % ctx.attr.os_path,
    )
    return [DefaultInfo(files = depset([output]))]

loader_ini = rule(
    implementation = _loader_ini_impl,
    attrs = {
        "os_path": attr.string(mandatory = True, values = ["linux-gnu", "linux-musl"]),
    },
)

def _split_debug_elf_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    binary = ctx.actions.declare_file(ctx.label.name + "/dd_library_loader.so")
    debug = ctx.actions.declare_file(ctx.label.name + "/dd_library_loader.so.debug")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._splitter.path,
            foreign.objcopy.path,
            foreign.strip.path,
            ctx.file.binary.path,
            binary.path,
            debug.path,
        ],
        inputs = depset(
            [ctx.file._splitter, ctx.file.binary],
            transitive = [foreign.files, foreign.compiler_files],
        ),
        outputs = [binary, debug],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "SOURCE_DATE_EPOCH": "0",
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "SplitPhpLoaderDebug",
        progress_message = "Splitting debug information for %s" % ctx.label,
        use_default_shell_env = False,
    )
    return [
        DefaultInfo(files = depset([binary, debug])),
        OutputGroupInfo(
            binary = depset([binary]),
            debug = depset([debug]),
        ),
    ]

split_debug_elf = rule(
    implementation = _split_debug_elf_impl,
    attrs = {
        "binary": attr.label(allow_single_file = True, mandatory = True),
        "_splitter": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:split-debug-elf.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _loader_elf_check_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._validator.path,
            foreign.objdump.path,
            foreign.nm.path,
            foreign.objcopy.path,
            ctx.file.binary.path,
            ctx.file.debug.path,
            ctx.attr.architecture,
            ctx.attr.libc,
            ctx.attr.soname,
            ctx.file.version.path,
            ctx.file._debuglink_validator.path,
            marker.path,
        ],
        inputs = depset(
            [ctx.file._validator, ctx.file._debuglink_validator, ctx.file.binary, ctx.file.debug, ctx.file.version],
            transitive = [foreign.files, foreign.compiler_files],
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
        mnemonic = "VerifyPhpLoaderElf",
        progress_message = "Verifying %s ELF contract" % ctx.label,
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

loader_elf_check = rule(
    implementation = _loader_elf_check_impl,
    attrs = {
        "architecture": attr.string(mandatory = True),
        "binary": attr.label(allow_single_file = True, mandatory = True),
        "debug": attr.label(allow_single_file = True, mandatory = True),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "soname": attr.string(default = "dd_library_loader.so"),
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_debuglink_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:verify-debuglink.py",
        ),
        "_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:verify-loader-elf.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _loader_version_header_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    output = ctx.actions.declare_file("loader_version.h")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [ctx.file._generator.path, ctx.file.version.path, output.path],
        inputs = depset([ctx.file._generator, ctx.file.version], transitive = [foreign.files]),
        outputs = [output],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "GeneratePhpLoaderVersionHeader",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([output]))]

loader_version_header = rule(
    implementation = _loader_version_header_impl,
    attrs = {
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_generator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/loader:generate-loader-version-header.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)
