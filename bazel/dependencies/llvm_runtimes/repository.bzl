"""Checksum-locked LLVM source repository for target runtime builds."""

_LOCK = Label("//bazel/dependencies/llvm_runtimes:sources.json")

def _validate_source(lock):
    if lock.get("schema_version") != 1:
        fail("unsupported LLVM runtime source schema: %r" % lock.get("schema_version"))
    source = lock.get("source")
    if not source:
        fail("LLVM runtime source lock has no source record")
    for key in ["name", "version", "urls", "sha256", "strip_prefix"]:
        if not source.get(key):
            fail("LLVM runtime source is missing required key %s" % key)
    if source["name"] != "llvm-project" or source["version"] != "20.1.4":
        fail("LLVM runtime source must remain pinned to llvm-project 20.1.4")
    if len(source["sha256"]) != 64:
        fail("LLVM runtime source has an invalid SHA-256 digest")
    for url in source["urls"]:
        if not url.endswith("/llvm-project-20.1.4.src.tar.xz"):
            fail("unexpected LLVM runtime source URL: %s" % url)
    return source

def _llvm_runtime_sources_repository_impl(rctx):
    source = _validate_source(json.decode(rctx.read(rctx.attr.lock)))
    rctx.download_and_extract(
        url = source["urls"],
        sha256 = source["sha256"],
        stripPrefix = source["strip_prefix"],
    )
    rctx.file("llvm_runtime_sources.lock.json", json.encode(source) + "\n")
    rctx.file("llvm_runtime_sources.marker", "llvm-project 20.1.4 runtime sources\n")
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = ["//visibility:public"])

exports_files([
    "llvm_runtime_sources.lock.json",
    "llvm_runtime_sources.marker",
])

filegroup(
    name = "runtimes",
    srcs = glob([
        "cmake/**",
        "libc/**",
        "libcxx/**",
        "libcxxabi/**",
        "libunwind/**",
        "llvm/cmake/**",
        "runtimes/**",
    ], allow_empty = False),
)

filegroup(
    name = "compiler_rt",
    srcs = glob([
        "cmake/**",
        "compiler-rt/**",
        "llvm/cmake/**",
        "runtimes/**",
    ], allow_empty = False),
)

alias(name = "root", actual = "llvm_runtime_sources.marker")
alias(name = "lock", actual = "llvm_runtime_sources.lock.json")
""",
    )
    return rctx.repo_metadata(reproducible = True)

llvm_runtime_sources_repository = repository_rule(
    implementation = _llvm_runtime_sources_repository_impl,
    attrs = {
        "lock": attr.label(allow_single_file = [".json"], default = _LOCK),
    },
)

def _llvm_runtime_sources_impl(mctx):
    llvm_runtime_sources_repository(name = "llvm_runtime_sources_20_1_4")
    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = ["llvm_runtime_sources_20_1_4"],
        root_module_direct_dev_deps = [],
    )

llvm_runtime_sources = module_extension(implementation = _llvm_runtime_sources_impl)
