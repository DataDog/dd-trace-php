"""Deterministic archive rules for packaging-compatible SSI payload trees."""

load(":artifacts.bzl", "ProductArtifactProjectionInfo")

_SsiPayloadInfo = provider(
    doc = "An SSI payload tree assembled and symlink-audited by ssi_payload.",
    fields = {"tree": "The audited payload tree artifact."},
)

def _deterministic_ssi_bundle_impl(ctx):
    tools = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    args = ctx.actions.args()
    args.add(ctx.file._runner.path)
    args.add("--tar", tools.tar.path)
    args.add("--root", ctx.file.payload.path)
    args.add("--output", ctx.outputs.out.path)
    args.add("--prefix", ctx.attr.prefix)
    for executable in ctx.attr.executable_paths:
        args.add_all(["--executable", executable])
    validation_files = []
    for target in ctx.attr.validation:
        validation_files.extend(target[DefaultInfo].files.to_list())
    ctx.actions.run(
        executable = tools.shell,
        arguments = [args],
        inputs = depset([ctx.file.payload, ctx.file._runner] + validation_files, transitive = [tools.files]),
        outputs = [ctx.outputs.out],
        mnemonic = "DeterministicSsiBundle",
        env = dict(tools.env, PATH = ":".join(tools.path_entries), SOURCE_DATE_EPOCH = "0", TZ = "UTC", LANG = "C"),
        progress_message = "Creating deterministic SSI archive %{label}",
    )
    return [DefaultInfo(files = depset([ctx.outputs.out]))]

deterministic_ssi_bundle = rule(
    implementation = _deterministic_ssi_bundle_impl,
    attrs = {
        "payload": attr.label(allow_single_file = True, mandatory = True, providers = [_SsiPayloadInfo]),
        "prefix": attr.string(default = "dd-library-php-ssi"),
        "executable_paths": attr.string_list(),
        "_runner": attr.label(default = "//tools/bazel:deterministic-tar.sh", allow_single_file = True),
        "validation": attr.label_list(),
    },
    outputs = {"out": "%{name}.tar.gz"},
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
    doc = "Archives a fully assembled SSI tree with normalized metadata.",
)

def _ssi_payload_impl(ctx):
    tools = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    out = ctx.actions.declare_directory(ctx.label.name)
    args = ctx.actions.args()
    args.add(ctx.file._runner.path)
    args.add("--output", out.path)
    args.add("--version-file", ctx.file.version.path)
    sources_by_destination = {}
    projection_validation = []
    for target, destination in ctx.attr.srcs.items():
        files = target.files.to_list()
        if len(files) != 1:
            fail("SSI source %s must provide exactly one file" % target.label)
        if ProductArtifactProjectionInfo in target:
            projection = target[ProductArtifactProjectionInfo]
            if projection.file != files[0]:
                fail("SSI projection %s does not expose its declared file" % target.label)
            projection_validation.extend(projection.validation.to_list())
        if destination in sources_by_destination:
            fail("SSI destination is mapped more than once: %s" % destination)
        sources_by_destination[destination] = files[0]
    copy_args = []
    copy_inputs = []
    for destination in sorted(sources_by_destination.keys()):
        source = sources_by_destination[destination]
        copy_args.extend(["--copy", source.path, destination])
        copy_inputs.append(source)
    args.add_all(copy_args)
    for executable in ctx.attr.executable_paths:
        args.add_all(["--executable", executable])
    for required in ctx.attr.required_paths:
        args.add("--require", required)
    explicit_validation = []
    for target in ctx.attr.validation:
        explicit_validation.extend(target[DefaultInfo].files.to_list())
    ctx.actions.run(
        executable = tools.shell,
        arguments = [args],
        inputs = depset(copy_inputs + projection_validation + explicit_validation + [ctx.file.version, ctx.file._runner], transitive = [tools.files]),
        outputs = [out],
        mnemonic = "AssembleSsiPayload",
        env = dict(tools.env, PATH = ":".join(tools.path_entries), SOURCE_DATE_EPOCH = "0", TZ = "UTC", LANG = "C"),
        progress_message = "Assembling SSI payload %{label}",
    )
    return [
        DefaultInfo(files = depset([out])),
        _SsiPayloadInfo(tree = out),
    ]

ssi_payload = rule(
    implementation = _ssi_payload_impl,
    attrs = {
        "srcs": attr.label_keyed_string_dict(allow_files = True, mandatory = True),
        "validation": attr.label_list(),
        "required_paths": attr.string_list(mandatory = True),
        "executable_paths": attr.string_list(),
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_runner": attr.label(default = "//tools/bazel:assemble-ssi-payload.sh", allow_single_file = True),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
    doc = "Creates a relocatable SSI payload tree from one-file source-to-path mappings.",
)
