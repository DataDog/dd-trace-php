#!/usr/bin/env python3
"""Configure the pinned reference builds and export their native action inventory.

Maintenance tool only. Ordinary Bazel builds never invoke CMake. Run against a
prefetched execroot containing the reference execution-tool launchers.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess


def configure(execroot, work, arch, libc, stage):
    here = Path(__file__).resolve().parent
    external = execroot / "external"
    source = external / "+llvm_runtime_sources+llvm_runtime_sources_20_1_4"
    llvm = external / "+http_archive+llvm_20_1_4_dist_x86_64"
    candidates = sorted((execroot / "bazel-out").glob("*/bin/bazel/dependencies/exec_tools/cmake_x86_64/bin/cmake"))
    tools = next((p.parent.parent.parent for p in candidates if p.is_file()), None)
    if tools is None:
        raise RuntimeError("Build an explicit *_cmake target on an x86-64 execution host first")
    sysroot = external / ("+sysroots+sysroot_%s_%s" % ("centos7" if libc == "glibc" else "alpine322", arch))
    if not (sysroot / "usr/include/stdlib.h").is_file():
        raise RuntimeError("Target sysroot headers are missing: " + str(sysroot))
    output = work / (arch + "_" + libc) / stage
    output.parent.mkdir(parents=True, exist_ok=True)
    reference = here / ("build-builtins.sh" if stage == "builtins" else "build-runtime.sh")
    script = reference.read_text().split('"$cmake" --build')[0]
    script = script.replace('-G "Unix Makefiles"', '-DCMAKE_EXPORT_COMPILE_COMMANDS=ON -G "Unix Makefiles"')
    script_path = output.parent / (stage + "-configure.sh")
    script_path.write_text(script)
    candidates = [p for suffix in ["_cmake", ""]
                  for p in sorted((execroot / "bazel-out").glob("*/bin/bazel/dependencies/llvm_runtimes/%s_%s%s.resource-dir" % (arch, libc, suffix)))]
    resource = next((p for p in candidates if (p / "lib/linux").is_dir()), None)
    if resource is None:
        raise RuntimeError("Build %s_%s_cmake with its matching --config=linux-ARCH-LIBC first" % (arch, libc))
    args = [str(tools / "sh"), str(script_path), "--source", str(source), "--output", str(output),
            "--target", arch + "-unknown-linux-" + ("gnu" if libc == "glibc" else "musl"),
            "--sysroot", str(sysroot), "--jobs", "4"]
    for flag, tool in {"cmake": "cmake_x86_64/bin/cmake", "make": "make", "cc": "clang", "cxx": "clang++",
                       "ar": "llvm-ar", "ranlib": "llvm-ranlib", "ld": "ld.lld", "nm": "llvm-nm",
                       "objcopy": "llvm-objcopy", "objdump": "llvm-objdump", "strip": "llvm-strip", "python": "python3"}.items():
        args.extend(["--" + flag, str(tools / tool)])
    for flag in ["builtins-static", "crtbegin", "crtend"]:
        args.extend(["--" + flag, str(output.parent / flag)])
    if stage != "builtins":
        args.extend(["--libc", libc, "--compiler-rt-root", str(resource)])
        for flag in ["libcxx-static", "libcxx-shared", "libcxxabi-static", "libcxxabi-shared", "libunwind-static", "libunwind-shared", "cxx20-smoke"]:
            args.extend(["--" + flag, str(output.parent / flag)])
        if libc == "glibc":
            for flag in ["asan-shared", "asan-static", "asan-cxx-static", "asan-preinit-static"]:
                args.extend(["--" + flag, str(output.parent / flag)])
    # A reference build need not materialize every launcher alias. Supply
    # maintenance utilities from the pinned static BusyBox, never the host PATH.
    utility_dir = work / "utilities"
    utility_dir.mkdir(parents=True, exist_ok=True)
    busybox = external / "+execution_tools+exec_tools_alpine322_x86_64/root/bin/busybox.static"
    for name in subprocess.check_output([str(busybox), "--list"], text=True).splitlines():
        alias = utility_dir / name
        if not alias.exists():
            alias.symlink_to(busybox)
    env = dict(os.environ, HERMETIC_LLVM_ROOT=str(llvm),
               HERMETIC_EXEC_RUNTIME_ROOT=str(external / "+execution_runtimes+exec_runtime_debian12_x86_64/root"),
               HERMETIC_TOOLS_ROOT=str(external / "+execution_tools+exec_tools_alpine322_x86_64/root"),
               PATH=str(tools) + ":" + str(utility_dir), PYTHONDONTWRITEBYTECODE="1", PYTHONNOUSERSITE="1", PYTHONHASHSEED="0")
    with (output.parent / (stage + "-configure.log")).open("w") as log:
        subprocess.run(args, env=env, cwd=execroot, stdout=log, stderr=subprocess.STDOUT, check=True)
    return Path(str(output) + ".build"), source, sysroot, resource, llvm, tools


def export_stage(build, source, sysroot, resource, llvm, tools, execroot):
    replacements = [(str(resource), "@RESOURCE@"), (str(source), "@SOURCE@"),
                    (str(sysroot), "@SYSROOT@"), (str(build), "@BUILD@"),
                    (str(tools / "ld.lld"), "@LD@"), (str(llvm), "@LLVM@"),
                    (str(execroot), "@EXECROOT@")]

    def normalize(value):
        if value.startswith(("-I/", "-L/")):
            # CMake emits paths through runtimes/cmake/Modules/../../../libc.
            # Sandboxes only contain compilation inputs, not those CMake dirs.
            value = value[:2] + os.path.normpath(value[2:])
        for old, new in replacements:
            value = value.replace(old, new)
        return value

    def build_path(value, cwd):
        return normalize(os.path.normpath(str(cwd / value)))

    commands = json.loads((build / "compile_commands.json").read_text())
    # compiler-rt creates CRT objects using custom commands, outside CMake's
    # compilation database. Preserve the actual commands, not inferred flags.
    for makefile in sorted(build.glob("compiler-rt/lib/builtins/CMakeFiles/clang_rt.crt*.dir/build.make")):
        for line in makefile.read_text().splitlines():
            if line.startswith("\tcd ") and " -c " in line:
                prefix, command = line.strip().split(" && ", 1)
                words = shlex.split(command)
                commands.append(dict(directory=shlex.split(prefix)[1], command=command, file=words[words.index("-c") + 1]))
    flags = []
    objects = []
    generated_sources = {}
    for entry in commands:
        words = shlex.split(entry["command"])
        compiler = Path(words.pop(0)).name
        out = words[words.index("-o") + 1]
        del words[words.index("-o"):words.index("-o") + 2]
        src = words[words.index("-c") + 1]
        del words[words.index("-c"):words.index("-c") + 2]
        # AArch64 outline atomic assembly copies are generated verbatim from
        # lse.S; SIZE/MODEL/L_* defines distinguish the compilation actions.
        if not Path(src).exists() and re.search(r"/outline_atomic.*\.S$", src):
            src = str(source / "compiler-rt/lib/builtins/aarch64/lse.S")
        if src.startswith(str(build) + "/"):
            generated_sources[normalize(src)] = Path(src).read_text()
        normalized = [normalize(word) for word in words]
        if normalized not in flags:
            flags.append(normalized)
        objects.append([build_path(out, Path(entry["directory"])), normalize(src), flags.index(normalized), compiler])
    links = []
    for link in sorted(build.rglob("link.txt")):
        if "cxx_experimental.dir" in str(link):
            continue
        words = shlex.split(link.read_text().splitlines()[0])
        tool = Path(words.pop(0)).name
        cwd = link.parent.parent.parent
        if tool == "llvm-ar":
            words[0] = "rcsD"  # one deterministic archive action, including its index
            output = words[1]
        else:
            output = words[words.index("-o") + 1]
        words = [build_path(w, cwd) if not w.startswith("-") and (w.endswith((".o", ".a", ".so.1.0")) or w == output) else normalize(w) for w in words]
        links.append([build_path(output, cwd), tool, words])
    generated = {}
    for name in ["__config_site", "__assertion_handler"]:
        path = build / "include/c++/v1" / name
        if path.exists():
            generated["include/c++/v1/" + name] = path.read_text()
    return dict(flags=flags, objects=objects, links=links, generated=generated, generated_sources=generated_sources)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--execroot", required=True, type=Path)
    parser.add_argument("--work", required=True, type=Path)
    parser.add_argument("--export-only", action="store_true")
    parser.add_argument("--target", action="append", choices=[a + "_" + l for a in ["x86_64", "aarch64"] for l in ["glibc", "musl"]])
    args = parser.parse_args()
    args.execroot = args.execroot.resolve()
    args.work = args.work.resolve()
    here = Path(__file__).resolve().parent
    for target in args.target or ["x86_64_glibc", "x86_64_musl", "aarch64_glibc", "aarch64_musl"]:
        arch, libc = target.rsplit("_", 1)
        manifest = {}
        for stage in ["builtins", "runtimes"]:
            metadata = args.work / target / (stage + "-paths.json")
            if args.export_only:
                paths = [Path(p) for p in json.loads(metadata.read_text())]
            else:
                paths = configure(args.execroot, args.work, arch, libc, stage)
                metadata.write_text(json.dumps([str(p) for p in paths]))
            build = paths[0]
            manifest[stage] = export_stage(*paths, args.execroot)
            print(build, flush=True)
        manifest["source_sha256"] = json.loads((here / "sources.json").read_text())["source"]["sha256"]
        manifest["reference_sha256"] = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in [here / "build-builtins.sh", here / "build-runtime.sh"]}
        destination = here / "manifests" / (target + ".bzl")
        destination.parent.mkdir(exist_ok=True)
        destination.write_text('"""Generated from LLVM 20.1.4 CMake; see generate_manifest.py."""\n\nRUNTIME = ' + json.dumps(manifest, indent=4, sort_keys=True) + "\n")


if __name__ == "__main__":
    main()
