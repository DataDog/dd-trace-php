"""Native tracer C sources with explicit PHP and curl SDK dependencies."""

load("@rules_cc//cc:cc_library.bzl", "cc_library")
load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/dependencies/target_curl_oci:defs.bzl", "TargetCurlInfo")
load("//bazel/php:php_toolchain.bzl", "PhpToolchainInfo")
load("//bazel/php:product_matrix.bzl", "product_matrix_rows")
load("//bazel/platforms:transitions.bzl", "configured_target", "single_platform_transition")

TracerCInfo = provider(
    doc = "A production-profile tracer C archive and its target ABI/runtime contract.",
    fields = {
        "arch": "amd64 or arm64",
        "archive": "The single PIC static tracer C archive.",
        "asan": "Whether the archive was compiled for an ASan product row.",
        "cc_info": "CcInfo consumed by the final extension link.",
        "curl": "Validated TargetCurlInfo for the target runtime closure.",
        "libc": "glibc or musl",
        "php": "Validated PhpToolchainInfo used to compile the archive.",
        "target_triple": "Canonical target triple shared by PHP and curl.",
    },
)

def _transitioned_target(value, description):
    if type(value) != "list" or len(value) != 1:
        fail("%s expected exactly one transitioned target, got %r" % (description, value))
    return value[0]

def _tracer_c_product_input_impl(ctx):
    library = _transitioned_target(ctx.attr.library, "tracer C library")
    php_target = _transitioned_target(ctx.attr.php, "tracer PHP SDK")
    curl_target = _transitioned_target(ctx.attr.curl, "tracer curl SDK")
    php = php_target[PhpToolchainInfo]
    curl = curl_target[TargetCurlInfo]

    expected_arch = "amd64" if ctx.attr.architecture == "x86_64" else "arm64"
    if php.target_arch != expected_arch or curl.arch != expected_arch:
        fail("tracer C target architecture mismatch: wanted %s, PHP=%s, curl=%s" % (expected_arch, php.target_arch, curl.arch))
    if php.target_libc != ctx.attr.libc or curl.libc != ctx.attr.libc:
        fail("tracer C target libc mismatch: wanted %s, PHP=%s, curl=%s" % (ctx.attr.libc, php.target_libc, curl.libc))
    if php.target_triple != curl.target_triple:
        fail("tracer C PHP/curl target triple mismatch: %s != %s" % (php.target_triple, curl.target_triple))
    if php.asan != ctx.attr.asan:
        fail("tracer C ASan mismatch: expected %s, PHP profile %s reports %s" % (
            ctx.attr.asan,
            php.runtime_profile,
            php.asan,
        ))

    original_cc_info = library[CcInfo]
    objects = []
    for linker_input in original_cc_info.linking_context.linker_inputs.to_list():
        for linked_library in linker_input.libraries:
            objects.extend(linked_library.pic_objects)
    if not objects:
        fail("tracer C library exposes no PIC objects for PHP-symbol weakening")
    archive = ctx.actions.declare_file(ctx.label.name + ".weakened.a")
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    mockgen_info = ctx.attr._mockgen[DefaultInfo]
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._weaken_runner.path,
            foreign.ar.path,
            foreign.ranlib.path,
            ctx.executable._mockgen.path,
            php.php_symbol_reference.path,
            archive.path,
        ] + [obj.path for obj in objects],
        inputs = depset(
            [ctx.file._weaken_runner, ctx.executable._mockgen, php.php_symbol_reference] + objects,
            transitive = [
                foreign.files,
                foreign.compiler_files,
                mockgen_info.default_runfiles.files,
                mockgen_info.data_runfiles.files,
            ],
        ),
        outputs = [archive],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "SOURCE_DATE_EPOCH": "0",
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "WeakenTracerPhpSymbols",
        use_default_shell_env = False,
    )
    whole_archive_input = cc_common.create_linker_input(
        owner = ctx.label,
        additional_inputs = depset([archive, curl.library]),
        user_link_flags = depset([
            "-Wl,--whole-archive,%s,--no-whole-archive" % archive.path,
            # Link the validated target DSO directly.  Forwarding curl's
            # imported-library CcInfo makes Bazel synthesize an execroot
            # _solib RUNPATH, which cannot appear in a relocatable PHP
            # extension.  The packaged target closure supplies this SONAME.
            curl.library.path,
        ]),
    )
    cc_info = CcInfo(
        compilation_context = original_cc_info.compilation_context,
        linking_context = cc_common.create_linking_context(
            linker_inputs = depset(
                [whole_archive_input],
                # The compiled cc_library context contains both its individual
                # objects and its archive.  Forwarding it together with the
                # explicit whole-archive input links every tracer object twice.
                # Preserve only the PHP/curl dependency link contexts here;
                # the tracer archive itself is contributed exactly once above.
                transitive = [
                    php_target[CcInfo].linking_context.linker_inputs,
                ],
            ),
        ),
    )
    info = TracerCInfo(
        arch = expected_arch,
        archive = archive,
        asan = ctx.attr.asan,
        cc_info = cc_info,
        curl = curl,
        libc = ctx.attr.libc,
        php = php,
        target_triple = php.target_triple,
    )
    providers = [
        DefaultInfo(files = depset([archive])),
        cc_info,
        info,
    ]
    if OutputGroupInfo in library:
        providers.append(library[OutputGroupInfo])
    return providers

_tracer_c_product_input = rule(
    implementation = _tracer_c_product_input_impl,
    attrs = {
        "architecture": attr.string(mandatory = True, values = ["aarch64", "x86_64"]),
        "asan": attr.bool(mandatory = True),
        "curl": attr.label(cfg = single_platform_transition, mandatory = True, providers = [CcInfo, TargetCurlInfo]),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "library": attr.label(cfg = single_platform_transition, mandatory = True, providers = [CcInfo]),
        "matrix_platform": attr.string(mandatory = True),
        "php": attr.label(cfg = single_platform_transition, mandatory = True, providers = [CcInfo, PhpToolchainInfo]),
        "_allowlist_function_transition": attr.label(
            default = "@bazel_tools//tools/allowlists/function_transition_allowlist",
        ),
        "_mockgen": attr.label(
            cfg = "exec",
            default = "//:php_sidecar_mockgen",
            executable = True,
        ),
        "_weaken_runner": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer:weaken-php-symbols.sh",
        ),
    },
    provides = [CcInfo, TracerCInfo],
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _version_header_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    output = ctx.actions.declare_file("ext/version.h")
    direct_output = ctx.actions.declare_file("version.h")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [ctx.file._generator.path, ctx.file.version.path, output.path, direct_output.path],
        inputs = depset([ctx.file._generator, ctx.file.version], transitive = [foreign.files]),
        outputs = [output, direct_output],
        env = dict(foreign.env, **{
            "HOME": "/nonexistent",
            "LANG": "C",
            "LC_ALL": "C",
            "PATH": ":".join(foreign.path_entries),
            "TZ": "UTC",
        }),
        execution_requirements = {"no-network": "1"},
        mnemonic = "GenerateTracerVersionHeader",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([output, direct_output]))]

_version_header = rule(
    implementation = _version_header_impl,
    attrs = {
        "version": attr.label(allow_single_file = True, mandatory = True),
        "_generator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer:generate-version-header.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _config_header_impl(ctx):
    output = ctx.actions.declare_file(ctx.attr.libc + "/config.h")
    backtrace = """#define HAVE_BACKTRACE 1
#define HAVE_EXECINFO_H 1
#define backtrace_size_t int
""" if ctx.attr.libc == "glibc" else ""
    ctx.actions.write(
        output,
        """#ifndef DDTRACE_BAZEL_CONFIG_H
#define DDTRACE_BAZEL_CONFIG_H
#define COMPILE_DL_DDTRACE 1
#define HAVE_LINUX_CAPABILITY_H 1
#define HAVE_LINUX_SECUREBITS_H 1
#define HAVE_VALGRIND 1
%s
#endif
""" % backtrace,
    )
    return [DefaultInfo(files = depset([output]))]

_config_header = rule(
    implementation = _config_header_impl,
    attrs = {"libc": attr.string(mandatory = True, values = ["glibc", "musl"])},
)

def _archive_check_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._validator.path,
            foreign.ar.path,
            foreign.nm.path,
            foreign.objdump.path,
            ctx.file.archive.path,
            ctx.attr.architecture,
            marker.path,
        ],
        inputs = depset(
            [ctx.file._validator, ctx.file.archive],
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
        mnemonic = "ValidateTracerComsArchive",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

_archive_check = rule(
    implementation = _archive_check_impl,
    attrs = {
        "architecture": attr.string(mandatory = True, values = ["aarch64", "x86_64"]),
        "archive": attr.label(allow_single_file = True, mandatory = True),
        "_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer:validate-coms-archive.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

def _full_archive_check_impl(ctx):
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [
            ctx.file._validator.path,
            foreign.ar.path,
            foreign.nm.path,
            foreign.objdump.path,
            ctx.file.archive.path,
            ctx.attr.architecture,
            ctx.attr.libc,
            str(ctx.attr.expected_members),
            marker.path,
        ],
        inputs = depset(
            [ctx.file._validator, ctx.file.archive],
            transitive = [
                foreign.files,
                foreign.compiler_files,
                ctx.attr.config_probe[DefaultInfo].files,
            ],
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
        mnemonic = "ValidateFullTracerCArchive",
        use_default_shell_env = False,
    )
    return [DefaultInfo(files = depset([marker]))]

_full_archive_check = rule(
    implementation = _full_archive_check_impl,
    attrs = {
        "architecture": attr.string(mandatory = True, values = ["aarch64", "x86_64"]),
        "archive": attr.label(allow_single_file = True, mandatory = True),
        "config_probe": attr.label(mandatory = True),
        "expected_members": attr.int(mandatory = True),
        "libc": attr.string(mandatory = True, values = ["glibc", "musl"]),
        "_validator": attr.label(
            allow_single_file = True,
            default = "//bazel/products/tracer:validate-full-archive.sh",
        ),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type"],
)

_PUBLISHED_MINORS = (
    struct(expected_members = 97, minor = "8.5", source_suffix = "85"),
)

_RELEASES_BY_MINOR = {release.minor: release for release in _PUBLISHED_MINORS}

def _variant_for_row(row):
    base = "%s_%s" % (row.target_arch, row.target_libc)
    if row.sdk_family == "release":
        name = base + "_release"
    elif row.shared_build:
        name = base + "_shared"
    elif row.sdk_family == "alpine-legacy":
        name = base + "_legacy"
    else:
        name = base
    return struct(
        arch = row.target_arch,
        architecture = "x86_64" if row.target_arch == "amd64" else "aarch64",
        asan = row.sanitizer == "asan",
        curl = "//bazel/dependencies/target_curl_oci:curl_" + row.target_libc,
        libc = row.target_libc,
        name = name,
        php = row.sdk_label,
        platform = row.matrix_platform,
        profile = row.abi_profile,
    )

def _full_tracer_variant(row):
    variant = _variant_for_row(row)
    release = _RELEASES_BY_MINOR[row.php_minor]
    profile = variant.profile
    profile_suffix = "" if profile == "nts" else "_" + profile.replace("-", "_")
    suffix = "php%s%s" % (release.source_suffix, profile_suffix)
    php = variant.php
    full_compiled = "_%s_%s_c_compiled" % (variant.name, suffix)
    config_probe_compiled = "_%s_%s_target_config_probe_compiled" % (variant.name, suffix)
    config_probe = "%s_%s_target_config_probe" % (variant.name, suffix)
    product = "%s_%s_c" % (variant.name, suffix)
    cc_library(
        name = config_probe_compiled,
        srcs = ["target-config-probe.c"],
        copts = [
            "-Wall",
            "-Werror",
            "-pthread",
            "-std=gnu11",
        ] + (["-DDDTRACE_EXPECT_BACKTRACE=1"] if variant.libc == "glibc" else []),
        visibility = ["//visibility:private"],
        deps = [
            ":_config_headers_" + variant.libc,
            php,
        ],
    )
    configured_target(
        name = config_probe,
        actual = ":" + config_probe_compiled,
        matrix_platform = variant.platform,
    )
    cc_library(
        name = full_compiled,
        srcs = ["//:tracer_php%s_sources" % release.source_suffix],
        hdrs = [":_version_header"],
        copts = [
            "-DDDTRACE",
            "-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1",
            "-Wall",
            "-fms-extensions",
            "-Wno-microsoft-anon-tag",
            "-O2",
            "-fvisibility=hidden",
            "-g",
            "-pthread",
            "-std=gnu11",
        ] + (["-mtls-dialect=gnu2"] if variant.architecture == "x86_64" else []),
        defines = [
            "HAVE_CONFIG_H=1",
            "_GNU_SOURCE",
        ],
        includes = ["."],
        visibility = ["//visibility:private"],
        deps = [
            "//:tracer_native_headers",
            ":_config_headers_" + variant.libc,
            "@valgrind_3_25_1_source//:headers",
            variant.curl,
            php,
        ],
    )
    _tracer_c_product_input(
        name = product,
        architecture = variant.architecture,
        asan = variant.asan,
        curl = variant.curl,
        libc = variant.libc,
        library = ":" + full_compiled,
        matrix_platform = variant.platform,
        php = php,
    )
    _full_archive_check(
        name = product + "_check",
        architecture = variant.architecture,
        archive = ":" + product,
        config_probe = ":" + config_probe,
        expected_members = release.expected_members,
        libc = variant.libc,
    )
    return [
        ":" + product,
        ":" + product + "_check",
    ]

def tracer_c_matrix():
    """Compiles the retained curl-dependent PHP 8.5 tracer C products."""
    _version_header(
        name = "_version_header",
        version = "//:VERSION",
    )
    for libc in ("glibc", "musl"):
        _config_header(name = "_config_header_" + libc, libc = libc)
        cc_library(
            name = "_config_headers_" + libc,
            hdrs = [":_config_header_" + libc],
            strip_include_prefix = "/bazel/products/tracer/" + libc,
            visibility = ["//visibility:private"],
        )
    rows = product_matrix_rows()
    coms_rows = {}
    for row in rows:
        if (row.php_minor == "8.5" and
            row.abi_profile == "nts" and
            row.sanitizer == "none" and
            not row.shared_build and
            row.sdk_family in ("bookworm", "alpine")):
            key = "%s_%s" % (row.target_arch, row.target_libc)
            if key in coms_rows:
                fail("duplicate primary tracer COMS row %s" % key)
            coms_rows[key] = row
    if len(coms_rows) != 4:
        fail("expected four primary PHP 8.5 tracer COMS rows, got %r" % sorted(coms_rows.keys()))

    outputs = []
    for key in sorted(coms_rows.keys()):
        row = coms_rows[key]
        variant = _variant_for_row(row)
        php85 = row.sdk_label
        compiled = "_%s_coms_compiled" % variant.name
        cc_library(
            name = compiled,
            srcs = ["//:tracer/coms.c"],
            hdrs = [":_version_header"],
            copts = [
                "-DDDTRACE",
                "-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1",
                "-Wall",
                "-Werror",
                "-fms-extensions",
                "-pthread",
                "-Wno-microsoft-anon-tag",
                "-std=gnu11",
            ],
            defines = [
                "HAVE_CONFIG_H=1",
                "_GNU_SOURCE",
            ],
            includes = ["."],
            visibility = ["//visibility:private"],
            deps = [
                "//:tracer_native_headers",
                ":_config_headers_" + variant.libc,
                "@valgrind_3_25_1_source//:headers",
                variant.curl,
                php85,
            ],
        )
        configured_target(
            name = variant.name + "_coms",
            actual = ":" + compiled,
            matrix_platform = variant.platform,
        )
        _archive_check(
            name = variant.name + "_coms_check",
            architecture = variant.architecture,
            archive = ":" + variant.name + "_coms",
        )
        outputs.extend([
            ":" + variant.name + "_coms",
            ":" + variant.name + "_coms_check",
        ])
    published = {}
    for key in sorted(coms_rows.keys()):
        row = coms_rows[key]
        if row.name in published:
            fail("tracer product duplicates normalized PHP row %s" % row.name)
        published[row.name] = True
        outputs.extend(_full_tracer_variant(row))
    if len(published) != 4:
        fail("expected four PHP 8.5 NTS tracer C products, got %d" % len(published))
    native.filegroup(
        name = "tracer_c_all",
        srcs = outputs,
    )
