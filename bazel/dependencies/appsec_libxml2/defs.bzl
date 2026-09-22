"""Target-configured AppSec libxml2 adapter.

The vendored source is intentionally separate from PHP's libxml2.  Its
thread mode comes from the configured PHP SDK, so an NTS/ZTS product cannot
accidentally link an archive built for the other ABI.
"""

load("@rules_cc//cc:find_cc_toolchain.bzl", "find_cc_toolchain")
load("@rules_cc//cc/common:cc_common.bzl", "cc_common")
load("@rules_cc//cc/common:cc_info.bzl", "CcInfo")
load("//bazel/dependencies/llvm_runtimes:runtime.bzl", "LlvmRuntimeInfo")
load("//bazel/php:php_toolchain.bzl", "PhpToolchainInfo")

AppsecLibxml2Info = provider(
    fields = ["archive", "asan", "cc_info", "headers", "libc", "runtime", "smoke", "target_triple", "zts"],
)

def _appsec_libxml2_impl(ctx):
    php = ctx.attr.php[PhpToolchainInfo]
    runtime = ctx.attr.runtime[LlvmRuntimeInfo]
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    if php.target_libc != sysroot.libc:
        fail("PHP SDK libc %s differs from libxml2 sysroot %s" % (php.target_libc, sysroot.libc))
    expected_arch = "amd64" if sysroot.target_triple.startswith("x86_64-") else "arm64"
    if php.target_arch != expected_arch:
        fail("PHP SDK architecture %s differs from libxml2 target %s" % (php.target_arch, sysroot.target_triple))
    if runtime.target_triple != sysroot.target_triple or runtime.libc != sysroot.libc:
        fail("LLVM runtime must match libxml2 sysroot target and libc")
    asan = ctx.target_platform_has_constraint(ctx.attr._asan_constraint[platform_common.ConstraintValueInfo])
    if php.asan != asan:
        fail("PHP SDK ASan setting must match libxml2 target platform")
    if asan and (not runtime.asan_supported or not runtime.asan_shared):
        fail("ASan libxml2 requires the matching declared LLVM ASan runtime")

    cc_toolchain = find_cc_toolchain(ctx)
    features = cc_common.configure_features(ctx = ctx, cc_toolchain = cc_toolchain)
    prefix = ctx.actions.declare_directory(ctx.label.name + ".prefix")
    archive = ctx.actions.declare_file(ctx.label.name + ".libxml2.a")
    smoke = ctx.actions.declare_file(ctx.label.name + ".allocator-smoke")
    shared_smoke = ctx.actions.declare_file(ctx.label.name + ".allocator-smoke.so")
    args = ctx.actions.args()
    args.add(ctx.file._runner.path)
    args.add_all(["--source", ctx.file.source_root.dirname, "--prefix", prefix.path])
    args.add_all(["--archive", archive.path, "--smoke", smoke.path, "--shared-smoke", shared_smoke.path])
    args.add_all(["--smoke-source", ctx.file._smoke_source.path])
    args.add_all(["--version-script", ctx.file.version_script.path])
    args.add_all(["--cmake", foreign.cmake.path, "--make", foreign.make.path])
    args.add_all(["--cc", foreign.clang.path, "--ar", foreign.ar.path, "--ranlib", foreign.ranlib.path, "--ld", foreign.lld.path, "--objdump", foreign.objdump.path])
    args.add_all(["--target", sysroot.target_triple, "--sysroot", sysroot.root.dirname, "--threads", "ON" if php.zts else "OFF"])
    args.add_all(["--crtbegin", runtime.crtbegin.path, "--crtend", runtime.crtend.path, "--builtins", runtime.builtins_static.path, "--runtime-unwind", runtime.libunwind_static.path])
    if asan:
        args.add_all(["--asan", "--asan-shared", runtime.asan_shared.path, "--target-resource-dir", runtime.resource_dir.path])
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [args],
        inputs = depset(ctx.files.srcs + [ctx.file.source_root, ctx.file._runner, ctx.file._smoke_source, ctx.file.version_script], transitive = [foreign.files, foreign.compiler_files, sysroot.files, runtime.files]),
        outputs = [prefix, archive, smoke, shared_smoke],
        env = dict(foreign.env, HOME = "/nonexistent", LANG = "C", LC_ALL = "C", PATH = ":".join(foreign.path_entries), SOURCE_DATE_EPOCH = "0", TZ = "UTC", ZERO_AR_DATE = "1"),
        execution_requirements = {"no-network": "1"},
        mnemonic = "BuildAppsecLibxml2",
    )
    library = cc_common.create_library_to_link(actions = ctx.actions, cc_toolchain = cc_toolchain, feature_configuration = features, static_library = archive)
    linker_input = cc_common.create_linker_input(
        owner = ctx.label,
        libraries = depset([library]),
        user_link_flags = depset(["-lpthread"] if php.zts else []),
    )
    cc_info = CcInfo(
        compilation_context = cc_common.create_compilation_context(headers = depset([prefix]), includes = depset([prefix.path + "/include/libxml2"])),
        linking_context = cc_common.create_linking_context(linker_inputs = depset([linker_input])),
    )
    return [DefaultInfo(files = depset([prefix, archive, smoke, shared_smoke])), cc_info, AppsecLibxml2Info(archive = archive, asan = asan, cc_info = cc_info, headers = prefix, libc = sysroot.libc, runtime = runtime, smoke = smoke, target_triple = sysroot.target_triple, zts = php.zts)]

appsec_libxml2 = rule(
    implementation = _appsec_libxml2_impl,
    attrs = {
        "php": attr.label(mandatory = True, providers = [PhpToolchainInfo]),
        "runtime": attr.label(mandatory = True, providers = [LlvmRuntimeInfo]),
        "source_root": attr.label(allow_single_file = True, default = "//appsec/third_party/libxml2:src/CMakeLists.txt"),
        "srcs": attr.label(allow_files = True, default = "//appsec/third_party/libxml2:vendored_sources"),
        "version_script": attr.label(allow_single_file = True, default = "//appsec/src/extension:ddappsec.version"),
        "_runner": attr.label(allow_single_file = True, default = "//bazel/dependencies/appsec_libxml2:build-libxml2.sh"),
        "_smoke_source": attr.label(allow_single_file = [".c"], default = "//bazel/dependencies/appsec_libxml2:allocator-smoke.c"),
        "_asan_constraint": attr.label(default = "//bazel/platforms:asan"),
    },
    fragments = ["cpp"],
    toolchains = ["@bazel_tools//tools/cpp:toolchain_type", "//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)

def _native_probe_impl(ctx):
    library = ctx.attr.library[AppsecLibxml2Info]
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    ctx.actions.run(
        executable = ctx.executable.busybox,
        arguments = ["sh", ctx.file._runner.path, ctx.executable.busybox.path, library.smoke.path, marker.path],
        inputs = [ctx.executable.busybox, ctx.file._runner, library.smoke],
        outputs = [marker],
        env = {"HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C", "PATH": "/nonexistent", "TZ": "UTC"},
        execution_requirements = {"no-network": "1"},
        mnemonic = "RunAppsecLibxml2NativeSmoke",
    )
    return [DefaultInfo(files = depset([marker]))]

appsec_libxml2_native_probe = rule(
    implementation = _native_probe_impl,
    attrs = {
        "busybox": attr.label(allow_single_file = True, cfg = "exec", executable = True, mandatory = True),
        "library": attr.label(mandatory = True, providers = [AppsecLibxml2Info]),
        "_runner": attr.label(allow_single_file = True, default = "//bazel/dependencies/gnu_libunwind:run-native-smoke.sh"),
    },
)

def _asan_native_probe_impl(ctx):
    library = ctx.attr.library[AppsecLibxml2Info]
    runtime = library.runtime
    if not library.asan or not runtime.asan_supported or not runtime.asan_shared:
        fail("ASan libxml2 native probe requires an ASan-built archive and runtime")
    if runtime.target_triple != library.target_triple or runtime.libc != "glibc":
        fail("ASan libxml2 native probe requires its matching glibc runtime")
    foreign = ctx.toolchains["//bazel/toolchains:hermetic_tools_type"].foreign
    sysroot = ctx.toolchains["//bazel/toolchains:sysroot_type"].sysroot
    if sysroot.target_triple != library.target_triple:
        fail("ASan libxml2 native probe sysroot does not match archive target")
    marker = ctx.actions.declare_file(ctx.label.name + ".passed")
    library_path = ":".join([runtime.asan_shared.dirname, sysroot.root.dirname + "/lib", sysroot.root.dirname + "/lib64", sysroot.root.dirname + "/usr/lib", sysroot.root.dirname + "/usr/lib64"])
    ctx.actions.run(
        executable = foreign.shell,
        arguments = [ctx.file._runner.path, foreign.objdump.path, sysroot.root.dirname + sysroot.dynamic_linker, library_path, library.smoke.path, marker.path, ctx.attr.machine, ctx.attr.elf_architecture, runtime.asan_shared_basename],
        inputs = depset([ctx.file._runner, library.smoke], transitive = [foreign.files, foreign.compiler_files, runtime.files, sysroot.files]),
        outputs = [marker],
        env = dict(foreign.env, HOME = "/nonexistent", LANG = "C", LC_ALL = "C", PATH = ":".join(foreign.path_entries), TZ = "UTC"),
        execution_requirements = {"no-network": "1"},
        mnemonic = "RunAppsecLibxml2AsanNativeSmoke",
    )
    return [DefaultInfo(files = depset([marker])), OutputGroupInfo(binary = depset([library.smoke]))]

appsec_libxml2_asan_native_probe = rule(
    implementation = _asan_native_probe_impl,
    attrs = {
        "elf_architecture": attr.string(mandatory = True),
        "machine": attr.string(mandatory = True),
        "library": attr.label(mandatory = True, providers = [AppsecLibxml2Info]),
        "_runner": attr.label(allow_single_file = True, default = "//bazel/dependencies/appsec_libxml2:run-asan-allocator-smoke.sh"),
    },
    toolchains = ["//bazel/toolchains:hermetic_tools_type", "//bazel/toolchains:sysroot_type"],
)
