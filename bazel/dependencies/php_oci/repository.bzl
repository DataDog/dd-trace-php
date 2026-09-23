"""Digest-locked PHP SDK snapshots imported from OCI profile layers.

The repositories normalize one self-contained installed PHP profile under
sdk/ and runtime/. They are deliberately profile snapshots, not reconstructed
final-image root filesystems. The source image index, platform manifest,
config and profile-layer digests remain in :lock and :provenance.
"""

_DEFAULT_LOCK = Label("//bazel/dependencies/php_oci:images.json")

def _sha256(digest, what):
    if not digest.startswith("sha256:") or len(digest) != 71:
        fail("%s has invalid OCI SHA-256 digest %r" % (what, digest))
    return digest[7:]

def _validate_record(record):
    required = [
        "repo_name",
        "image_family",
        "minor",
        "abi_profile",
        "declared_source_version",
        "observed_image_version",
        "target_libc",
        "target_triple",
        "filesystem_semantics",
        "image_descriptor_kind",
        "registry",
        "repository",
        "index_digest",
        "manifest_digest",
        "config_digest",
        "metadata",
        "expected_history_created_by",
        "platform",
        "source_prefix",
        "content_mode",
        "max_unpacked_size",
        "expected",
        "layers",
    ]
    for key in required:
        if key not in record:
            fail("PHP OCI record is missing required key %r: %r" % (key, record))
    if record["filesystem_semantics"] not in (
        "profile_copy_snapshot",
        "profile_layer_snapshot",
    ):
        fail("unsupported PHP OCI filesystem semantics: %r" % record["filesystem_semantics"])
    if len(record["layers"]) != 1:
        fail("PHP OCI profile snapshot %s must contain exactly one layer" % record["repo_name"])
    if record.get("image_descriptor_kind", "index") not in ("index", "manifest"):
        fail("unsupported PHP OCI image descriptor kind: %r" % record.get("image_descriptor_kind"))
    if record["content_mode"] not in ("headers", "full_profile"):
        fail("unsupported PHP OCI content mode: %r" % record["content_mode"])
    _sha256(record["index_digest"], "index")
    _sha256(record["manifest_digest"], "manifest")
    _sha256(record["config_digest"], "config")
    for key in ["index", "manifest", "config"]:
        if key not in record["metadata"]:
            fail("PHP OCI metadata is missing %s path" % key)
    platform = record["platform"]
    if platform.get("os") != "linux" or platform.get("arch") not in ("amd64", "arm64"):
        fail("unsupported PHP OCI platform: %r" % platform)
    expected = record["expected"]
    for key in [
        "php_version",
        "php_version_id",
        "php_api",
        "zend_module_api",
        "zend_extension_api",
        "arch",
        "debug",
        "zts",
        "asan",
    ]:
        if key not in expected:
            fail("PHP OCI expected metadata is missing %s" % key)
    if expected["arch"] != platform["arch"]:
        fail("PHP OCI expected arch differs from platform arch")
    if expected["php_version"] != record["observed_image_version"]:
        fail("PHP OCI expected version differs from its locked image version")
    if not expected["php_version"].startswith(record["minor"] + "."):
        fail("PHP OCI image version is outside its selected PHP minor")
    layer = record["layers"][0]
    for key in ["digest", "size", "media_type"]:
        if key not in layer:
            fail("PHP OCI layer is missing %s" % key)
    _sha256(layer["digest"], "layer")
    if type(layer["size"]) != "int" or layer["size"] <= 0:
        fail("PHP OCI layer size must be positive")
    if layer["media_type"] not in (
        "application/vnd.docker.image.rootfs.diff.tar.gzip",
        "application/vnd.oci.image.layer.v1.tar+gzip",
    ):
        fail("unsupported PHP OCI layer media type: %s" % layer["media_type"])

def _select_record(lock, repo_name):
    if lock.get("schema_version") != 1:
        fail("unsupported PHP OCI lock schema: %r" % lock.get("schema_version"))
    matches = [record for record in lock.get("imports", []) if record.get("repo_name") == repo_name]
    if len(matches) != 1:
        fail("expected exactly one PHP OCI import named %s, got %d" % (repo_name, len(matches)))
    _validate_record(matches[0])
    return matches[0]

def _registry_url(record, digest):
    return "https://%s/v2/%s/blobs/%s" % (record["registry"], record["repository"], digest)

def _download_token(rctx, record):
    auth = record.get("anonymous_auth")
    if not auth:
        fail("registry download requires anonymous_auth or checksum-addressed mirror URLs")
    token_file = "registry-token.json"
    result = rctx.download(
        url = auth["token_url"],
        output = token_file,
        allow_fail = True,
    )
    if not result.success:
        fail("unable to obtain anonymous OCI registry token: %s" % result.error)
    response = json.decode(rctx.read(token_file))
    if not rctx.delete(token_file):
        fail("failed to remove transient OCI registry token")
    token = response.get("token", response.get("access_token", ""))
    if not token:
        fail("OCI registry token response did not contain a token")
    return token

def _download_layer(rctx, record, layer, output):
    sha256 = _sha256(layer["digest"], "layer")
    urls = layer.get("urls", [])
    if urls:
        rctx.download(
            url = urls,
            output = output,
            sha256 = sha256,
        )
        return

    url = _registry_url(record, layer["digest"])

    # A checksum-keyed repository-cache hit succeeds here without contacting
    # the registry. This is what makes rebuilds offline after prefetch.
    result = rctx.download(
        url = url,
        output = output,
        sha256 = sha256,
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
        sha256 = sha256,
        # Bazel applies `headers` again after a redirect. Docker Hub may
        # redirect to a presigned S3 URL, which rejects a forwarded bearer
        # header. `auth` scopes the credential to the registry URL.
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
    fail("unsupported PHP OCI repository execution architecture: %s" % rctx.os.arch)

def _php_oci_repository_impl(rctx):
    record = _select_record(json.decode(rctx.read(rctx.attr.lock)), rctx.attr.repo_name)
    rctx.file("import.lock.json", json.encode(record) + "\n", executable = False)
    archives = []
    for index, layer in enumerate(record["layers"]):
        output = "downloads/%s-%s.layer.tgz" % (index, layer["digest"][7:])
        _download_layer(rctx, record, layer, output)
        archives.append(rctx.path(output))

    command = [
        str(rctx.path(_python(rctx))),
        str(rctx.path(rctx.attr._extractor)),
        "--lock",
        str(rctx.path("import.lock.json")),
        "--output",
        str(rctx.path("normalized")),
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
        timeout = 600,
    )
    if result.return_code:
        fail("PHP OCI profile extraction failed:\nstdout:\n%s\nstderr:\n%s" % (result.stdout, result.stderr))
    for archive in archives:
        if not rctx.delete(archive):
            fail("failed to remove imported OCI layer %s" % archive)
    rctx.file("oci-index.json", rctx.read(rctx.attr.index_metadata), executable = False)
    rctx.file("oci-manifest.json", rctx.read(rctx.attr.manifest_metadata), executable = False)
    rctx.file("oci-config.json", rctx.read(rctx.attr.config_metadata), executable = False)

    rctx.file(
        "provenance.json",
        json.encode({
            "config_digest": record["config_digest"],
            "filesystem_semantics": record["filesystem_semantics"],
            "index_digest": record["index_digest"],
            "manifest_digest": record["manifest_digest"],
            "observed": json.decode(rctx.read("normalized/observed.json")),
            "platform": record["platform"],
            "profile_layer_digests": [layer["digest"] for layer in record["layers"]],
            "repository": record["repository"],
            "source_tag": record.get("source_tag", ""),
        }) + "\n",
        executable = False,
    )
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = [\"//visibility:public\"])

exports_files([
    \"import.lock.json\",
    \"provenance.json\",
    \"normalized/sdk/.root\",
    \"normalized/symbols/php\",
    \"normalized/observed.json\",
    \"oci-index.json\",
    \"oci-manifest.json\",
    \"oci-config.json\",
])

filegroup(
    name = \"all\",
    srcs = glob([\"normalized/**\"]) + [\"import.lock.json\", \"provenance.json\"],
)

filegroup(name = \"headers\", srcs = glob([\"normalized/sdk/include/php/**\"], allow_empty = False))
filegroup(name = \"libs\", srcs = glob([\"normalized/runtime/lib/**\"], allow_empty = True))
filegroup(name = \"extensions\", srcs = glob([\"normalized/runtime/lib/php/extensions/**/*.so\"], allow_empty = True))
filegroup(
    name = \"config\",
    srcs = glob([
        \"normalized/runtime/conf.d/**\",
        \"normalized/runtime/etc/**\",
        \"normalized/runtime/*.ini\",
    ], allow_empty = True),
)
filegroup(name = \"runtime\", srcs = glob([\"normalized/runtime/**\"], allow_empty = True))

alias(name = \"sdk_root\", actual = \"normalized/sdk/.root\")
alias(name = \"lock\", actual = \"import.lock.json\")
alias(name = \"provenance\", actual = \"provenance.json\")
alias(name = \"observed\", actual = \"normalized/observed.json\")
alias(name = \"php_symbol_reference\", actual = \"normalized/symbols/php\")
""",
        executable = False,
    )
    return rctx.repo_metadata(reproducible = True)

php_oci_repository = repository_rule(
    implementation = _php_oci_repository_impl,
    attrs = {
        "repo_name": attr.string(mandatory = True),
        "lock": attr.label(allow_single_file = [".json"], mandatory = True),
        "python_aarch64": attr.label(allow_single_file = True, mandatory = True),
        "python_x86_64": attr.label(allow_single_file = True, mandatory = True),
        "index_metadata": attr.label(allow_single_file = [".json"], mandatory = True),
        "manifest_metadata": attr.label(allow_single_file = [".json"], mandatory = True),
        "config_metadata": attr.label(allow_single_file = [".json"], mandatory = True),
        "_extractor": attr.label(
            allow_single_file = True,
            default = Label("//bazel/dependencies/php_oci:extract_profile.py"),
        ),
    },
)

def _php_oci_imports_impl(mctx):
    tools = []
    locks = []
    for module in mctx.modules:
        for tool in module.tags.tool:
            if not module.is_root:
                fail("only the root module may select PHP OCI extraction tools")
            tools.append(tool)
        for lock in module.tags.lock:
            if not module.is_root:
                fail("only the root module may select a PHP OCI lock")
            locks.append(lock)
    if len(tools) != 1:
        fail("php_oci_imports.tool(...) must select exactly one pinned Python pair")
    lock_label = locks[0].file if locks else _DEFAULT_LOCK
    if len(locks) > 1:
        fail("php_oci_imports.lock(...) may be declared at most once")
    decoded = json.decode(mctx.read(lock_label))
    if decoded.get("schema_version") != 1:
        fail("unsupported PHP OCI lock schema")
    imports = decoded.get("imports", [])
    if not imports:
        fail("PHP OCI lock must contain at least one import")
    seen = {}
    for record in imports:
        _validate_record(record)
        if record["repo_name"] in seen:
            fail("duplicate PHP OCI repository name: %s" % record["repo_name"])
        seen[record["repo_name"]] = True
        php_oci_repository(
            name = record["repo_name"],
            repo_name = record["repo_name"],
            lock = lock_label,
            python_aarch64 = tools[0].python_aarch64,
            python_x86_64 = tools[0].python_x86_64,
            index_metadata = Label("//bazel/dependencies/php_oci:%s" % record["metadata"]["index"]),
            manifest_metadata = Label("//bazel/dependencies/php_oci:%s" % record["metadata"]["manifest"]),
            config_metadata = Label("//bazel/dependencies/php_oci:%s" % record["metadata"]["config"]),
        )

    # Consumers intentionally import only the SDK repositories their configured
    # matrix targets reference. Reporting all generated repositories as direct
    # dependencies would make `bazel mod tidy` demand 223 eager use_repo names.
    return mctx.extension_metadata(reproducible = True)

php_oci_imports = module_extension(
    implementation = _php_oci_imports_impl,
    tag_classes = {
        "lock": tag_class(attrs = {"file": attr.label(allow_single_file = [".json"], mandatory = True)}),
        "tool": tag_class(attrs = {
            "python_aarch64": attr.label(mandatory = True),
            "python_x86_64": attr.label(mandatory = True),
        }),
    },
)
