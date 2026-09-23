"""Digest-locked curl 7.61.1 SDKs imported from the CentOS 7 CI images."""

_LAYERS = {
    "amd64": struct(
        curl = struct(digest = "42896c230c38efe3eb4e138acd2d93b1d3736f62e37eb13a1bea04314400883b", size = 4516858),
        manifest = "f369675a3b0d8d8903e11a9fc93a1f799750ce3b7bdb4edafa895d37dc541087",
        openssl = struct(digest = "50fd10338b1a9b8de6c876131350c2dfdc8ba5bb4ed160a6d374a849fb7ab2db", size = 13875594),
        zlib = struct(digest = "d0130138ea4c4fc6cabf6b8d7627ac2a7e6314513802e26b07b2cf05a6bfecba", size = 752134),
    ),
    "arm64": struct(
        curl = struct(digest = "51f7bd7b9d8b087554670d46c84863ab968661c22290797f43dd4849e2ab0866", size = 4528737),
        manifest = "5b09ee9151f7c3af0d6591bdb358f1988b9d1251ac181bc0f7bf7aed6f711219",
        openssl = struct(digest = "0458747fa8eb5037dc8a95983d7063332773d2568ad16b157cf45d9166275c34", size = 13859131),
        zlib = struct(digest = "3030c8d9493e1431aadcf9964ddfd10cb36451a5ddbd38868caed5e1c1fb7470", size = 757340),
    ),
}

def _python(rctx):
    if rctx.os.arch in ("amd64", "x86_64"):
        return rctx.attr.python_x86_64
    if rctx.os.arch in ("aarch64", "arm64"):
        return rctx.attr.python_aarch64
    fail("unsupported repository execution architecture: %s" % rctx.os.arch)

def _token(rctx):
    output = "token.json"
    result = rctx.download(
        url = "https://auth.docker.io/token?service=registry.docker.io&scope=repository:datadog/dd-trace-ci:pull",
        output = output,
        allow_fail = True,
    )
    if not result.success:
        fail("cannot obtain Docker Hub token: %s" % result.error)
    response = json.decode(rctx.read(output))
    rctx.delete(output)
    token = response.get("token", response.get("access_token", ""))
    if not token:
        fail("Docker Hub token response lacks a token")
    return token

def _curl_sdk_repository_impl(rctx):
    layer = _LAYERS[rctx.attr.arch]
    archives = {}
    token = None
    for name, descriptor in (("curl", layer.curl), ("openssl", layer.openssl), ("zlib", layer.zlib)):
        archive = name + ".layer.tgz"
        url = "https://registry-1.docker.io/v2/datadog/dd-trace-ci/blobs/sha256:%s" % descriptor.digest
        result = rctx.download(url = url, output = archive, sha256 = descriptor.digest, allow_fail = True)
        if not result.success:
            if rctx.path(archive).exists:
                rctx.delete(archive)
            if token == None:
                token = _token(rctx)
            rctx.download(
                url = url,
                output = archive,
                sha256 = descriptor.digest,
                auth = {url: {
                    "type": "pattern",
                    "pattern": "Bearer <password>",
                    "password": token,
                }},
            )
        archives[name] = archive
    result = rctx.execute([
        str(rctx.path(_python(rctx))),
        str(rctx.path(rctx.attr._extractor)),
        "--arch",
        rctx.attr.arch,
        "--curl-archive",
        str(rctx.path(archives["curl"])),
        "--openssl-archive",
        str(rctx.path(archives["openssl"])),
        "--zlib-archive",
        str(rctx.path(archives["zlib"])),
        "--output",
        str(rctx.path("sdk")),
    ], environment = {"LANG": "C", "LC_ALL": "C", "PYTHONHASHSEED": "0"}, timeout = 300)
    if result.return_code:
        fail("curl SDK extraction failed:\nstdout:\n%s\nstderr:\n%s" % (result.stdout, result.stderr))
    for archive in archives.values():
        rctx.delete(archive)
    observed = json.decode(rctx.read("sdk/observed.json"))
    rctx.file("curl.lock.json", json.encode({
        "arch": rctx.attr.arch,
        "image_manifest_digest": "sha256:" + layer.manifest,
        "layers": {
            "curl": {"digest": "sha256:" + layer.curl.digest, "size": layer.curl.size},
            "openssl": {"digest": "sha256:" + layer.openssl.digest, "size": layer.openssl.size},
            "zlib": {"digest": "sha256:" + layer.zlib.digest, "size": layer.zlib.size},
        },
        "version": "7.61.1",
    }) + "\n")
    rctx.file("BUILD.bazel", """\
load("@rules_cc//cc:cc_import.bzl", "cc_import")

package(default_visibility = ["//visibility:public"])

exports_files(["curl.lock.json", "sdk/observed.json"])

cc_import(
    name = "curl",
    hdrs = glob(["sdk/include/**/*.h"]),
    includes = ["sdk/include"],
    shared_library = %s,
)

filegroup(name = "all", srcs = glob(["sdk/**"]) + ["curl.lock.json"])
filegroup(name = "headers", srcs = glob(["sdk/include/**/*.h"]))
filegroup(name = "runtime", srcs = glob(["sdk/dependencies/**/*.so*"]))
filegroup(name = "data", srcs = [])
alias(name = "library", actual = %s)
alias(name = "header", actual = "sdk/include/curl/curlver.h")
alias(name = "observed", actual = "sdk/observed.json")
alias(name = "lock", actual = "curl.lock.json")
""" % (repr("sdk/" + observed["library"]), repr("sdk/" + observed["library"])))
    return rctx.repo_metadata(reproducible = True)

curl_sdk_repository = repository_rule(
    implementation = _curl_sdk_repository_impl,
    attrs = {
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "python_aarch64": attr.label(allow_single_file = True, mandatory = True),
        "python_x86_64": attr.label(allow_single_file = True, mandatory = True),
        "_extractor": attr.label(default = Label("//bazel/dependencies/target_curl_oci:extract_curl.py"), allow_single_file = True),
    },
)

def _alpine_curl_repository_impl(rctx):
    lock = json.decode(rctx.read(rctx.attr.lock))
    if lock.get("schema_version") != 1:
        fail("unsupported Alpine curl lock schema")
    selected = lock["targets"][rctx.attr.arch]
    archives = []
    locked_packages = []
    for index, package in enumerate(selected["packages"]):
        name, version, sha256 = package
        filename = "%s-%s.apk" % (name, version)
        url = "%s/%s/%s" % (lock["repository"], selected["apk_arch"], filename)
        output = "packages/%d-%s" % (index, filename)
        rctx.download(url = url, output = output, sha256 = sha256)
        archives.append(rctx.path(output))
        locked_packages.append({"name": name, "sha256": sha256, "url": url, "version": version})
    result = rctx.execute(
        [
            str(rctx.path(_python(rctx))),
            str(rctx.path(rctx.attr._apk_extractor)),
            "--format",
            "apk",
            "--output",
            str(rctx.path("sdk")),
        ] + [str(archive) for archive in archives],
        environment = {"LANG": "C", "LC_ALL": "C", "PYTHONHASHSEED": "0"},
        timeout = 300,
    )
    if result.return_code:
        fail("Alpine curl SDK extraction failed:\nstdout:\n%s\nstderr:\n%s" % (result.stdout, result.stderr))
    for archive in archives:
        rctx.delete(archive)
    rctx.file("curl.lock.json", json.encode({
        "arch": rctx.attr.arch,
        "libc": "musl",
        "packages": locked_packages,
        "target_triple": "x86_64-unknown-linux-musl" if rctx.attr.arch == "amd64" else "aarch64-unknown-linux-musl",
        "version": "8.14.1",
    }) + "\n")
    rctx.file("sdk/observed.json", json.encode({
        "arch": rctx.attr.arch,
        "libc": "musl",
        "library": "usr/lib/libcurl.so.4.8.0",
        "version": "8.14.1",
    }) + "\n")
    rctx.file("BUILD.bazel", """\
load("@rules_cc//cc:cc_import.bzl", "cc_import")

package(default_visibility = ["//visibility:public"])

exports_files([
    "curl.lock.json",
    "sdk/observed.json",
    "sdk/usr/include/curl/curlver.h",
    "sdk/usr/lib/libcurl.so.4.8.0",
])

cc_import(
    name = "curl",
    hdrs = glob(["sdk/usr/include/curl/*.h"]),
    includes = ["sdk/usr/include"],
    shared_library = "sdk/usr/lib/libcurl.so.4.8.0",
)

filegroup(name = "all", srcs = glob(["sdk/**"]) + ["curl.lock.json"])
filegroup(name = "runtime", srcs = glob(["sdk/usr/lib/*.so*"], exclude = ["sdk/usr/lib/libcurl.so*"]))
filegroup(name = "data", srcs = ["sdk/etc/ssl/certs/ca-certificates.crt"])
alias(name = "library", actual = "sdk/usr/lib/libcurl.so.4.8.0")
alias(name = "header", actual = "sdk/usr/include/curl/curlver.h")
alias(name = "observed", actual = "sdk/observed.json")
alias(name = "lock", actual = "curl.lock.json")
""")
    return rctx.repo_metadata(reproducible = True)

alpine_curl_repository = repository_rule(
    implementation = _alpine_curl_repository_impl,
    attrs = {
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "lock": attr.label(allow_single_file = True, mandatory = True),
        "python_aarch64": attr.label(allow_single_file = True, mandatory = True),
        "python_x86_64": attr.label(allow_single_file = True, mandatory = True),
        "_apk_extractor": attr.label(default = Label("//bazel/dependencies/sysroots:extract_packages.py"), allow_single_file = True),
    },
)

def _target_curl_oci_impl(mctx):
    tools = [tag for module in mctx.modules for tag in module.tags.tool]
    if len(tools) != 1:
        fail("target_curl_oci.tool(...) requires exactly one pinned Python pair")
    for arch in ("amd64", "arm64"):
        curl_sdk_repository(
            name = "target_curl_centos7_" + arch,
            arch = arch,
            python_aarch64 = tools[0].python_aarch64,
            python_x86_64 = tools[0].python_x86_64,
        )
        alpine_curl_repository(
            name = "target_curl_alpine322_" + arch,
            arch = arch,
            lock = "//bazel/dependencies/target_curl_oci:alpine322.json",
            python_aarch64 = tools[0].python_aarch64,
            python_x86_64 = tools[0].python_x86_64,
        )
    return mctx.extension_metadata(reproducible = True)

target_curl_oci = module_extension(
    implementation = _target_curl_oci_impl,
    tag_classes = {"tool": tag_class(attrs = {
        "python_aarch64": attr.label(mandatory = True),
        "python_x86_64": attr.label(mandatory = True),
    })},
)
