"""Checksum-locked Debian 12 runtime libraries for GNU execution tools."""

_LOCK = Label("//bazel/dependencies/exec_runtime:debian12.json")

_REPOSITORIES = (
    struct(name = "exec_runtime_debian12_x86_64", arch = "amd64"),
    struct(name = "exec_runtime_debian12_aarch64", arch = "arm64"),
)

def _validate_package(package):
    for key in ["name", "urls", "sha256"]:
        if key not in package:
            fail("execution-runtime package is missing required key %r: %r" % (key, package))
    if not package["urls"]:
        fail("execution-runtime package %s has no download URLs" % package["name"])
    if len(package["sha256"]) != 64:
        fail("execution-runtime package %s has an invalid SHA-256 digest" % package["name"])
    for url in package["urls"]:
        is_snapshot_file = url.startswith("https://snapshot.debian.org/file/") and len(url.split("/")[-1]) == 40
        if not url.endswith(".deb") and not is_snapshot_file:
            fail("execution-runtime package is not a Debian archive: %s" % url)

def _select_runtime(lock, arch):
    if lock.get("schema_version") != 1:
        fail("unsupported execution-runtime lock schema: %r" % lock.get("schema_version"))
    matches = [entry for entry in lock.get("exec_runtimes", []) if entry.get("target", {}).get("arch") == arch]
    if len(matches) != 1:
        fail("expected exactly one execution runtime for %s, got %d" % (arch, len(matches)))
    selected = matches[0]
    for key in ["arch", "libc", "libc_version", "exec_triple", "dynamic_linker"]:
        if key not in selected.get("target", {}):
            fail("execution runtime %s is missing target key %s" % (arch, key))
    if not selected.get("coverage"):
        fail("execution runtime %s has no declared tool coverage" % arch)
    if not selected.get("packages"):
        fail("execution runtime %s has no packages" % arch)
    for package in selected["packages"]:
        _validate_package(package)
    return selected

def _host_python(rctx):
    arch = rctx.os.arch
    if arch in ["amd64", "x86_64"]:
        return rctx.path(rctx.attr.python_x86_64)
    if arch in ["aarch64", "arm64"]:
        return rctx.path(rctx.attr.python_aarch64)
    fail("unsupported execution host architecture for runtime extraction: %s" % arch)

def _execution_runtime_repository_impl(rctx):
    selected = _select_runtime(json.decode(rctx.read(rctx.attr.lock)), rctx.attr.arch)
    archives = []
    for index, package in enumerate(selected["packages"]):
        output = "packages/%s-%s.deb" % (index, package["name"].replace(":", "_"))
        rctx.download(
            output = output,
            sha256 = package["sha256"],
            url = package["urls"],
        )
        archives.append(rctx.path(output))

    result = rctx.execute(
        [
            str(_host_python(rctx)),
            str(rctx.path(rctx.attr._extractor)),
            "--output",
            str(rctx.path("root")),
        ] + [str(archive) for archive in archives],
        environment = {
            "LANG": "C",
            "LC_ALL": "C",
            "PYTHONHASHSEED": "0",
        },
        quiet = False,
        timeout = 600,
    )
    if result.return_code:
        fail("execution-runtime extraction failed:\nstdout:\n%s\nstderr:\n%s" % (result.stdout, result.stderr))
    for archive in archives:
        if not rctx.delete(archive):
            fail("failed to remove extracted package archive %s" % archive)

    triple = "x86_64-linux-gnu" if rctx.attr.arch == "amd64" else "aarch64-linux-gnu"
    loader_name = "ld-linux-x86-64.so.2" if rctx.attr.arch == "amd64" else "ld-linux-aarch64.so.1"
    rctx.file("exec_runtime.marker", "Debian 12 glibc execution runtime\n", executable = False)
    rctx.file("exec_runtime.lock.json", json.encode(selected) + "\n", executable = False)
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = [\"//visibility:public\"])

exports_files([
    \"exec_runtime.marker\",
    \"exec_runtime.lock.json\",
    \"root/lib/%s/%s\",
    \"root/lib/%s/libc.so.6\",
    \"root/lib/%s/libz.so.1\",
    \"root/usr/lib/%s/libxml2.so.2\",
])

filegroup(
    name = \"all\",
    srcs = glob([\"root/**\"], allow_empty = False) + [\"exec_runtime.lock.json\"],
)

alias(name = \"root\", actual = \"exec_runtime.marker\")
alias(name = \"lock\", actual = \"exec_runtime.lock.json\")
alias(name = \"loader\", actual = \"root/lib/%s/%s\")
alias(name = \"lib_anchor\", actual = \"root/lib/%s/libc.so.6\")
alias(name = \"usr_lib_anchor\", actual = \"root/usr/lib/%s/libxml2.so.2\")
""" % (triple, loader_name, triple, triple, triple, triple, loader_name, triple, triple),
        executable = False,
    )
    return rctx.repo_metadata(reproducible = True)

execution_runtime_repository = repository_rule(
    implementation = _execution_runtime_repository_impl,
    attrs = {
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "lock": attr.label(allow_single_file = [".json"], default = _LOCK),
        "python_aarch64": attr.label(allow_single_file = True, mandatory = True),
        "python_x86_64": attr.label(allow_single_file = True, mandatory = True),
        "_extractor": attr.label(
            allow_single_file = True,
            default = Label("//bazel/dependencies/exec_runtime:extract_debs.py"),
        ),
    },
)

def _execution_runtimes_impl(mctx):
    tools = []
    for module in mctx.modules:
        for tool in module.tags.tool:
            if not module.is_root:
                fail("only the root module may select execution-runtime extraction tools")
            tools.append(tool)
    if len(tools) != 1:
        fail("execution_runtimes.tool must declare exactly one pinned Python pair")

    direct_deps = []
    for repository in _REPOSITORIES:
        execution_runtime_repository(
            name = repository.name,
            arch = repository.arch,
            python_aarch64 = tools[0].python_aarch64,
            python_x86_64 = tools[0].python_x86_64,
        )
        direct_deps.append(repository.name)
    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = direct_deps,
        root_module_direct_dev_deps = [],
    )

execution_runtimes = module_extension(
    implementation = _execution_runtimes_impl,
    tag_classes = {
        "tool": tag_class(attrs = {
            "python_aarch64": attr.label(mandatory = True),
            "python_x86_64": attr.label(mandatory = True),
        }),
    },
)
