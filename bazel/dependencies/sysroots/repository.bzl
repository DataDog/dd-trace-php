"""Checksum-locked CentOS 7 and Alpine 3.22 target sysroots.

The extension creates these repositories:

  @sysroot_centos7_x86_64//:all
  @sysroot_centos7_aarch64//:all
  @sysroot_alpine322_x86_64//:all
  @sysroot_alpine322_aarch64//:all

Each repository also exports :root (a marker at the assembled sysroot root),
:headers, :libraries, and :lock. Package archives are fetched and extracted
only during repository setup. Build actions consume the assembled files and do
not invoke a package manager or an extractor.

The extractor requires an explicitly declared Python executable. This keeps
repository setup from searching PATH or selecting a host interpreter.
"""

_REPOSITORIES = (
    struct(name = "sysroot_centos7_x86_64", lock = "centos7.json", arch = "amd64"),
    struct(name = "sysroot_centos7_aarch64", lock = "centos7.json", arch = "arm64"),
    struct(name = "sysroot_alpine322_x86_64", lock = "alpine322.json", arch = "amd64"),
    struct(name = "sysroot_alpine322_aarch64", lock = "alpine322.json", arch = "arm64"),
)

def _validate_package(package):
    for key in ["name", "urls", "sha256"]:
        if key not in package:
            fail("sysroot package is missing required key %r: %r" % (key, package))
    if not package["urls"]:
        fail("sysroot package %s has no download URLs" % package["name"])
    if len(package["sha256"]) != 64:
        fail("sysroot package %s has an invalid SHA-256 digest" % package["name"])

def _select_sysroot(lock, arch):
    if lock.get("schema_version") != 1:
        fail("unsupported sysroot lock schema: %r" % lock.get("schema_version"))
    matches = [entry for entry in lock.get("sysroots", []) if entry.get("target", {}).get("arch") == arch]
    if len(matches) != 1:
        fail("expected exactly one sysroot for architecture %s, got %d" % (arch, len(matches)))
    selected = matches[0]
    for key in ["arch", "libc", "libc_version", "target_triple", "dynamic_linker"]:
        if key not in selected["target"]:
            fail("sysroot target %s is missing required key %s" % (arch, key))
    if not selected.get("packages"):
        fail("sysroot target %s has no packages" % arch)
    for package in selected["packages"]:
        _validate_package(package)
    return selected

def _archive_format(url):
    if url.endswith(".rpm"):
        return "rpm"
    if url.endswith(".apk"):
        return "apk"
    fail("unsupported sysroot package format: %s" % url)

def _sysroot_repository_impl(rctx):
    lock = json.decode(rctx.read(rctx.attr.lock))
    selected = _select_sysroot(lock, rctx.attr.arch)
    archives = []
    archive_format = None

    for index, package in enumerate(selected["packages"]):
        current_format = _archive_format(package["urls"][0])
        if archive_format == None:
            archive_format = current_format
        elif archive_format != current_format:
            fail("a sysroot cannot mix RPM and APK package formats")

        output = "packages/%s-%s" % (index, package["urls"][0].split("/")[-1])
        rctx.download(
            url = package["urls"],
            output = output,
            sha256 = package["sha256"],
        )
        archives.append(rctx.path(output))

    host_arch = rctx.os.arch
    if host_arch in ("amd64", "x86_64"):
        python = rctx.attr.python_x86_64
    elif host_arch in ("aarch64", "arm64"):
        python = rctx.attr.python_aarch64
    else:
        fail("unsupported sysroot repository execution architecture: %s" % host_arch)
    command = [
        str(rctx.path(python)),
        str(rctx.path(rctx.attr._extractor)),
        "--format",
        archive_format,
        "--output",
        str(rctx.path(".")),
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
        fail("sysroot extraction failed:\nstdout:\n%s\nstderr:\n%s" % (result.stdout, result.stderr))

    for archive in archives:
        if not rctx.delete(archive):
            fail("failed to remove extracted package archive %s" % archive)

    rctx.file("sysroot.marker", "assembled from checksum-locked packages\n", executable = False)
    rctx.file("sysroot.lock.json", json.encode(selected) + "\n", executable = False)
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = [\"//visibility:public\"])

exports_files([\"sysroot.marker\", \"sysroot.lock.json\"])

filegroup(
    name = \"all\",
    srcs = glob([\"**\"], exclude = [\"BUILD.bazel\"]),
)

filegroup(
    name = \"headers\",
    srcs = glob([\"include/**\", \"usr/include/**\"], allow_empty = True),
)

filegroup(
    name = \"libraries\",
    srcs = glob(
        [\"lib/**\", \"lib64/**\", \"usr/lib/**\", \"usr/lib64/**\"],
        allow_empty = True,
    ),
)

alias(name = \"root\", actual = \"sysroot.marker\")
alias(name = \"lock\", actual = \"sysroot.lock.json\")
""",
        executable = False,
    )
    return rctx.repo_metadata(reproducible = True)

sysroot_repository = repository_rule(
    implementation = _sysroot_repository_impl,
    attrs = {
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "lock": attr.label(allow_single_file = [".json"], mandatory = True),
        "python_aarch64": attr.label(allow_single_file = True, mandatory = True),
        "python_x86_64": attr.label(allow_single_file = True, mandatory = True),
        "_extractor": attr.label(
            allow_single_file = True,
            default = Label("//bazel/dependencies/sysroots:extract_packages.py"),
        ),
    },
)

def _sysroots_impl(mctx):
    tool_tags = []
    for module in mctx.modules:
        for tool in module.tags.tool:
            if not module.is_root:
                fail("only the root module may select the sysroot extraction tool")
            tool_tags.append(tool)
    if len(tool_tags) != 1:
        fail("sysroots.tool(...) must declare exactly one pair of pinned Python executables")

    direct_deps = []
    for repository in _REPOSITORIES:
        sysroot_repository(
            name = repository.name,
            arch = repository.arch,
            lock = Label("//bazel/dependencies/sysroots:%s" % repository.lock),
            python_aarch64 = tool_tags[0].python_aarch64,
            python_x86_64 = tool_tags[0].python_x86_64,
        )
        direct_deps.append(repository.name)

    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = direct_deps,
        root_module_direct_dev_deps = [],
    )

sysroots = module_extension(
    implementation = _sysroots_impl,
    tag_classes = {
        "tool": tag_class(attrs = {
            "python_aarch64": attr.label(mandatory = True),
            "python_x86_64": attr.label(mandatory = True),
        }),
    },
)
