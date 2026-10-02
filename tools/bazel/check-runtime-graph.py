#!/usr/bin/env python3
"""Audit an aquery --output=jsonproto normal tracer action graph."""

import argparse
from collections import Counter
import json
from pathlib import Path
import re


def audit(graph, arch):
    fragments = {str(f["id"]): f for f in graph["pathFragments"]}
    paths = {}

    def path(identifier):
        identifier = str(identifier)
        if identifier not in paths:
            fragment = fragments[identifier]
            parent = fragment.get("parentId")
            paths[identifier] = (path(parent) + "/" if parent else "") + fragment["label"]
        return paths[identifier]

    artifacts = {str(a["id"]): path(a["pathFragmentId"]) for a in graph["artifacts"]}
    dep_sets = {str(d["id"]): d for d in graph.get("depSetOfFiles", [])}
    input_cache = {}

    def inputs(identifier):
        identifier = str(identifier)
        if identifier not in input_cache:
            group = dep_sets[identifier]
            files = {artifacts[str(a)] for a in group.get("directArtifactIds", [])}
            for child in group.get("transitiveDepSetIds", []):
                files.update(inputs(child))
            input_cache[identifier] = files
        return input_cache[identifier]

    counts = Counter()
    identities = {}
    errors = []
    for action in graph["actions"]:
        mnemonic = action["mnemonic"]
        counts[mnemonic] += 1
        args = action.get("arguments", [])
        outputs = [artifacts[str(a)] for a in action.get("outputIds", [])]
        if mnemonic in ["BuildCompilerRtTargetRuntime", "BuildLlvmTargetRuntime", "LlvmRuntimeSmokeCompile", "LlvmRuntimeSmokeLink", "LlvmRuntimeAsanExports"]:
            errors.append("Unexpected normal-build action: " + mnemonic)
        if mnemonic in ["LlvmRuntimeCompile", "LlvmRuntimeAssemble"]:
            source = args[args.index("-c") + 1]
            if "/compiler-rt/" in source and "/builtins/" not in source:
                errors.append("Sanitizer compilation in normal graph: " + source)
            triples = [a.split("=", 1)[1] for a in args if a.startswith("--target=")]
            triples += [args[index + 1] for index, arg in enumerate(args[:-1]) if arg in ["-target", "--target"]]
            if not triples or not all(t.startswith(arch + "-") for t in triples):
                errors.append("Opposite-architecture runtime: " + str(triples))
            # Ignore only configuration/output spelling, preserving all actual
            # compile options and execution compiler identity in the key.
            normalized = list(args)
            del normalized[normalized.index("-o"):normalized.index("-o") + 2]
            key = tuple(re.sub(r"bazel-out/[^/]+/", "bazel-out/CONFIG/", a) for a in normalized)
            if key in identities:
                errors.append("Equivalent runtime compiled twice: " + source + " -> " + str([identities[key], outputs]))
            identities[key] = outputs
        if mnemonic in ["CppCompile", "ValidateDdtraceFatElf", "SplitDdtraceDebug"]:
            files = set()
            for identifier in action.get("inputDepSetIds", []):
                files.update(inputs(identifier))
            if mnemonic == "CppCompile":
                for file in files:
                    if "/bazel/dependencies/llvm_runtimes/" in file and (file.endswith((".a", ".o")) or ".so" in Path(file).name or file.endswith(".runtime")):
                        errors.append("Compilation waits for runtime link output: " + file)
            else:
                for file in files:
                    if "/bin/clang" in file or file.endswith("/bin/ld.lld") or "/cmake" in file:
                        errors.append("ELF inspection contains unrelated compiler/CMake tool: " + file)
    return dict(mnemonics=dict(sorted(counts.items())), runtime_compilations=len(identities), errors=sorted(set(errors)))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("graph", type=Path)
    parser.add_argument("--arch", choices=["x86_64", "aarch64"], required=True)
    args = parser.parse_args()
    result = audit(json.loads(args.graph.read_text()), args.arch)
    print(json.dumps(result, indent=2))
    raise SystemExit(bool(result["errors"]))


if __name__ == "__main__":
    main()
