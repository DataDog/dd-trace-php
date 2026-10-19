"""Digest-locked execution PHP runtimes reconstructed from final OCI images."""

_SDK_LOCK = Label("//bazel/dependencies/php_oci:images.json")

def _sha256(digest, what):
    if not digest.startswith("sha256:") or len(digest) != 71:
        fail("%s has invalid OCI SHA-256 digest %r" % (what, digest))
    return digest[7:]

def _download_token(rctx, record):
    auth = record.get("anonymous_auth")
    if not auth:
        fail("execution PHP OCI import requires anonymous_auth")
    token_file = "registry-token.json"
    result = rctx.download(url = auth["token_url"], output = token_file, allow_fail = True)
    if not result.success:
        fail("unable to obtain OCI registry token: %s" % result.error)
    response = json.decode(rctx.read(token_file))
    rctx.delete(token_file)
    token = response.get("token", response.get("access_token", ""))
    if not token:
        fail("OCI registry token response did not contain a token")
    return token

def _download_layer(rctx, record, descriptor, output):
    digest = descriptor["digest"]
    url = "https://%s/v2/%s/blobs/%s" % (record["registry"], record["repository"], digest)
    result = rctx.download(
        url = url,
        output = output,
        sha256 = _sha256(digest, "layer"),
        allow_fail = True,
    )
    if result.success:
        return
    if rctx.path(output).exists:
        rctx.delete(output)
    token = _download_token(rctx, record)
    rctx.download(
        url = url,
        output = output,
        sha256 = _sha256(digest, "layer"),
        auth = {url: {
            "type": "pattern",
            "pattern": "Bearer <password>",
            "password": token,
        }},
    )

def _python(rctx):
    if rctx.os.arch in ("amd64", "x86_64"):
        return rctx.attr.python_x86_64
    if rctx.os.arch in ("aarch64", "arm64"):
        return rctx.attr.python_aarch64
    fail("unsupported repository execution architecture: %s" % rctx.os.arch)

def _php_exec_oci_repository_impl(rctx):
    record = json.decode(rctx.attr.record)
    manifest = json.decode(rctx.read(rctx.attr.manifest_metadata))
    layers = manifest.get("layers", [])
    if not layers:
        fail("execution PHP OCI manifest has no layers")
    locked_layers = []
    archives = []
    for index, layer in enumerate(layers):
        digest = layer.get("digest", "")
        size = layer.get("size", 0)
        media_type = layer.get("mediaType", "")
        _sha256(digest, "layer")
        if type(size) != "int" or size <= 0:
            fail("execution PHP OCI layer has invalid size")
        if media_type not in (
            "application/vnd.docker.image.rootfs.diff.tar.gzip",
            "application/vnd.oci.image.layer.v1.tar+gzip",
        ):
            fail("unsupported execution PHP layer media type: %s" % media_type)
        descriptor = {"digest": digest, "media_type": media_type, "size": size}
        locked_layers.append(descriptor)
        output = "downloads/%s-%s.layer.tgz" % (index, digest[7:])
        _download_layer(rctx, record, descriptor, output)
        archives.append(rctx.path(output))

    lock = dict(record)
    lock["layers"] = locked_layers
    lock["filesystem_semantics"] = "final_image_layers"
    rctx.file("runtime.lock.json", json.encode(lock) + "\n", executable = False)
    command = [
        str(rctx.path(_python(rctx))),
        str(rctx.path(rctx.attr._extractor)),
        "--lock",
        str(rctx.path("runtime.lock.json")),
        "--output",
        str(rctx.path("runtime")),
        "--index",
        str(rctx.path(rctx.attr.index_metadata)),
        "--manifest",
        str(rctx.path(rctx.attr.manifest_metadata)),
        "--config",
        str(rctx.path(rctx.attr.config_metadata)),
    ] + [str(archive) for archive in archives]
    result = rctx.execute(
        command,
        environment = {
            "LANG": "C",
            "LC_ALL": "C",
            "PYTHONHASHSEED": "0",
        },
        quiet = False,
        timeout = 1800,
    )
    if result.return_code:
        fail("execution PHP OCI extraction failed:\nstdout:\n%s\nstderr:\n%s" % (result.stdout, result.stderr))
    for archive in archives:
        if not rctx.delete(archive):
            fail("failed to remove imported OCI layer %s" % archive)

    tokenizer = rctx.path("runtime/extensions/tokenizer.so")
    tokenizer_target = "alias(name = \"tokenizer\", actual = \"runtime/extensions/tokenizer.so\")" if tokenizer.exists else "filegroup(name = \"tokenizer\", srcs = [])"
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = [\"//visibility:public\"])

exports_files([
    \"runtime/bin/php\",
    \"runtime/lib/ld.so\",
    \"runtime/lib/.root\",
    \"runtime/observed.json\",
    \"runtime.lock.json\",
])

filegroup(name = \"all\", srcs = glob([\"runtime/**\"]) + [\"runtime.lock.json\"])
filegroup(name = \"libraries\", srcs = glob([\"runtime/lib/**\"]))
alias(name = \"php\", actual = \"runtime/bin/php\")
alias(name = \"loader\", actual = \"runtime/lib/ld.so\")
alias(name = \"lib_root\", actual = \"runtime/lib/.root\")
alias(name = \"observed\", actual = \"runtime/observed.json\")
%s
""" % tokenizer_target,
        executable = False,
    )
    return rctx.repo_metadata(reproducible = True)

php_exec_oci_repository = repository_rule(
    implementation = _php_exec_oci_repository_impl,
    attrs = {
        "record": attr.string(mandatory = True),
        "python_aarch64": attr.label(allow_single_file = True, mandatory = True),
        "python_x86_64": attr.label(allow_single_file = True, mandatory = True),
        "index_metadata": attr.label(allow_single_file = [".json"], mandatory = True),
        "manifest_metadata": attr.label(allow_single_file = [".json"], mandatory = True),
        "config_metadata": attr.label(allow_single_file = [".json"], mandatory = True),
        "_extractor": attr.label(
            allow_single_file = True,
            default = Label("//bazel/dependencies/php_exec_oci:extract_runtime.py"),
        ),
    },
)

def _selected_record(lock, arch):
    matches = [
        record
        for record in lock.get("imports", [])
        if record.get("image_family") == "bookworm" and
           record.get("minor") == "8.5" and
           record.get("abi_profile") == "nts" and
           record.get("platform", {}).get("arch") == arch
    ]
    if len(matches) != 1:
        fail("expected exactly one Bookworm PHP 8.5 NTS %s image, got %d" % (arch, len(matches)))
    return matches[0]

def _php_exec_oci_imports_impl(mctx):
    tools = []
    for module in mctx.modules:
        for tool in module.tags.tool:
            if not module.is_root:
                fail("only the root module may select execution PHP extraction tools")
            tools.append(tool)
    if len(tools) != 1:
        fail("php_exec_oci_imports.tool(...) must select exactly one pinned Python pair")
    lock = json.decode(mctx.read(_SDK_LOCK))
    for arch in ("amd64", "arm64"):
        record = _selected_record(lock, arch)
        php_exec_oci_repository(
            name = "php_exec_bookworm_" + arch,
            record = json.encode({
                "anonymous_auth": record.get("anonymous_auth"),
                "closure_schema": 2,
                "config_digest": record["config_digest"],
                "expected": record["expected"],
                "index_digest": record["index_digest"],
                "manifest_digest": record["manifest_digest"],
                "platform": record["platform"],
                "registry": record["registry"],
                "repository": record["repository"],
                "source_prefix": record["source_prefix"],
                "source_tag": record["source_tag"],
            }),
            python_aarch64 = tools[0].python_aarch64,
            python_x86_64 = tools[0].python_x86_64,
            index_metadata = Label("//bazel/dependencies/php_oci:%s" % record["metadata"]["index"]),
            manifest_metadata = Label("//bazel/dependencies/php_oci:%s" % record["metadata"]["manifest"]),
            config_metadata = Label("//bazel/dependencies/php_oci:%s" % record["metadata"]["config"]),
        )
    return mctx.extension_metadata(reproducible = True)

php_exec_oci_imports = module_extension(
    implementation = _php_exec_oci_imports_impl,
    tag_classes = {
        "tool": tag_class(attrs = {
            "python_aarch64": attr.label(mandatory = True),
            "python_x86_64": attr.label(mandatory = True),
        }),
    },
)
