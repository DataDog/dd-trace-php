"""Checksum-locked execution tools assembled from Alpine 3.22 packages.

The repositories expose an overlaid root/ containing every command and
runtime library, plus the compatibility labels consumed by the PHP foreign
build toolchain:

  @exec_tools_alpine322_x86_64//:bin/sh
  @exec_tools_alpine322_x86_64//:bin/tar
  @exec_tools_alpine322_x86_64//:bin/gzip
  @exec_tools_alpine322_x86_64//:make/usr/bin/make
  @exec_tools_alpine322_x86_64//:patch/usr/bin/patch
  @exec_tools_alpine322_x86_64//:all

The aarch64 repository has the same labels. bin/sh is static BusyBox. The
other compatibility labels expose raw musl executables for auditing and for
the static launcher targets in this package; consumers use the main-repository
launcher aliases rather than executing them directly on a glibc host.
"""

_REPOSITORIES = (
    struct(name = "exec_tools_alpine322_x86_64", arch = "amd64"),
    struct(name = "exec_tools_alpine322_aarch64", arch = "arm64"),
)

_LOCK = Label("//bazel/dependencies/exec_tools:alpine322.json")

def _validate_package(package):
    for key in ["name", "urls", "sha256"]:
        if key not in package:
            fail("execution-tool package is missing required key %r: %r" % (key, package))
    if not package["urls"]:
        fail("execution-tool package %s has no download URLs" % package["name"])
    if len(package["sha256"]) != 64:
        fail("execution-tool package %s has an invalid SHA-256 digest" % package["name"])
    for url in package["urls"]:
        if not url.endswith(".apk"):
            fail("execution-tool package is not an APK: %s" % url)

def _select_tools(lock, arch):
    if lock.get("schema_version") != 1:
        fail("unsupported execution-tool lock schema: %r" % lock.get("schema_version"))
    matches = [entry for entry in lock.get("exec_tools", []) if entry.get("target", {}).get("arch") == arch]
    if len(matches) != 1:
        fail("expected exactly one execution-tool closure for %s, got %d" % (arch, len(matches)))
    selected = matches[0]
    for key in ["arch", "libc", "libc_version", "exec_triple", "dynamic_linker"]:
        if key not in selected["target"]:
            fail("execution-tool target %s is missing required key %s" % (arch, key))
    if not selected.get("commands"):
        fail("execution-tool target %s has no command inventory" % arch)
    if not selected.get("packages"):
        fail("execution-tool target %s has no packages" % arch)
    for package in selected["packages"]:
        _validate_package(package)
    return selected

def _execution_tools_repository_impl(rctx):
    selected = _select_tools(json.decode(rctx.read(rctx.attr.lock)), rctx.attr.arch)
    for index, package in enumerate(selected["packages"]):
        archive = "archives/%s-%s.tar.gz" % (index, package["name"])
        rctx.download(
            url = package["urls"],
            output = archive,
            sha256 = package["sha256"],
        )
        # APK v2 files are concatenated gzip-compressed tar streams. Bazel's
        # tar extractor consumes the signed metadata and package data streams.
        rctx.extract(archive = archive, output = "root")
        if not rctx.delete(archive):
            fail("failed to remove extracted package archive %s" % archive)

    loader_arch = "x86_64" if rctx.attr.arch == "amd64" else "aarch64"
    rctx.symlink("root/bin/busybox.static", "bin/sh")
    rctx.symlink("root/bin/tar", "bin/tar")
    rctx.symlink("root/bin/gzip", "bin/gzip")
    rctx.symlink("root", "make")
    rctx.symlink("root", "patch")
    # libtoolize's relocatable override expects its macro directory below the
    # package data directory; Alpine installs the same files in share/aclocal.
    rctx.symlink("../aclocal", "root/usr/share/libtool/m4")
    # Retain the loader path used by the stage-two provider while keeping one
    # overlaid runtime root for dynamic loader lookup.
    rctx.symlink("root", "musl")
    # Perl embeds distro paths such as /usr/lib/perl5 in its default @INC.
    # The launcher loads this module through PERL5LIB before user code; it
    # replaces @INC so missing modules cannot fall through to the host.
    rctx.file(
        "root/usr/lib/perl5/core_perl/HermeticINC.pm",
        """\
package HermeticINC;
use strict;
use warnings;

sub import {
    my $root = $ENV{HERMETIC_TOOLS_ROOT}
        or die "HERMETIC_TOOLS_ROOT is required";
    @INC = (
        "$root/usr/share/autoconf",
        "$root/usr/share/automake-1.17",
        "$root/usr/lib/perl5/core_perl",
        "$root/usr/lib/perl5/vendor_perl",
        "$root/usr/share/perl5/core_perl",
        "$root/usr/share/perl5/vendor_perl",
    );
}

1;
""",
        executable = False,
    )
    rctx.file("exec_tools.lock.json", json.encode(selected) + "\n", executable = False)
    rctx.file(
        "BUILD.bazel",
        """\
package(default_visibility = [\"//visibility:public\"])

exports_files([
    \"bin/sh\",
    \"bin/tar\",
    \"bin/gzip\",
    \"make/usr/bin/make\",
    \"musl/lib/ld-musl-%s.so.1\",
    \"patch/usr/bin/patch\",
    \"root/bin/bash\",
    \"root/bin/grep\",
    \"root/bin/sed\",
    \"root/usr/bin/autoconf\",
    \"root/usr/bin/automake\",
    \"root/usr/bin/bison\",
    \"root/usr/bin/cmake\",
    \"root/bin/coreutils\",
    \"root/usr/bin/find\",
    \"root/usr/bin/libtool\",
    \"root/usr/bin/m4\",
    \"root/usr/bin/perl\",
    \"root/usr/bin/pkg-config\",
    \"root/usr/bin/python3\",
    \"root/usr/bin/re2c\",
    \"root/usr/lib/crt1.o\",
    \"root/usr/lib/crti.o\",
    \"root/usr/lib/crtn.o\",
    \"root/usr/lib/libc.a\",
    \"exec_tools.lock.json\",
])

filegroup(
    name = \"all\",
    srcs = glob([\"bin/**\", \"root/**\"], allow_empty = False) + [\"exec_tools.lock.json\"],
)

alias(name = \"lock\", actual = \"exec_tools.lock.json\")
alias(name = \"loader\", actual = \"musl/lib/ld-musl-%s.so.1\")
""" % (loader_arch, loader_arch),
        executable = False,
    )
    return rctx.repo_metadata(reproducible = True)

execution_tools_repository = repository_rule(
    implementation = _execution_tools_repository_impl,
    attrs = {
        "arch": attr.string(mandatory = True, values = ["amd64", "arm64"]),
        "lock": attr.label(
            allow_single_file = [".json"],
            default = _LOCK,
        ),
    },
)

def _execution_tools_impl(mctx):
    direct_deps = []
    for repository in _REPOSITORIES:
        execution_tools_repository(
            name = repository.name,
            arch = repository.arch,
        )
        direct_deps.append(repository.name)
    return mctx.extension_metadata(
        reproducible = True,
        root_module_direct_deps = direct_deps,
        root_module_direct_dev_deps = [],
    )

execution_tools = module_extension(implementation = _execution_tools_impl)
