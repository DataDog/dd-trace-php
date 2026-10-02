#!/usr/bin/env python3
"""Generate native edges and exact target/exec feature sets from Cargo output."""

import argparse
import json
import re
from pathlib import Path

TRIPLES = (
    "aarch64-unknown-linux-gnu",
    "aarch64-unknown-linux-musl",
    "x86_64-unknown-linux-gnu",
    "x86_64-unknown-linux-musl",
)
PRODUCT_FEATURE = {"profiler": "profiling", "tracer": "tracer"}
REPLACED_EXEC_UNITS = {
    "profiler": {
        "nix-0.29.0": "libdd-crashtracker build.rs replaced by declared TARGET metadata and emit_sicodes C action",
    },
    "tracer": {
        "hyper-rustls-0.27.7": "libddwaf-sys build.rs replaced by native libddwaf and LLVM 20 bindgen actions",
        "nix-0.29.0": "libdd-crashtracker build.rs replaced by declared TARGET metadata and emit_sicodes C action",
        "url-2.5.4": "libddwaf-sys build.rs replaced by native libddwaf and LLVM 20 bindgen actions",
    },
}
PACKAGE = re.compile(r"^([^ ]+) v([^ ]+?)(?: \(proc-macro\))?(?: \((.*)\))?$")


def parse_tree(path, source_root):
    nodes = {}
    edges = set()
    stack = []
    for raw in path.read_text().splitlines():
        depth_end = 0
        while depth_end < len(raw) and raw[depth_end].isdigit():
            depth_end += 1
        depth = int(raw[:depth_end])
        package, separator, feature_text = raw[depth_end:].partition("\t")
        if not separator:
            package, _, feature_text = raw[depth_end:].partition(r"\t")
        package = package.removesuffix(" (*)")
        feature_text = feature_text.removesuffix(" (*)")
        match = PACKAGE.match(package)
        if not match:
            raise ValueError("unrecognized cargo tree package: %s" % package)
        name, version, location = match.groups()
        if location and location.startswith(str(source_root)):
            relative = Path(location).relative_to(source_root)
            identity = ("path", str(relative) if str(relative) != "." else "")
        else:
            identity = ("registry", name, version)
        features = frozenset(filter(None, feature_text.split(",")))
        nodes.setdefault(identity, set()).update(features)
        if depth:
            edges.add((stack[depth - 1], identity))
        if len(stack) <= depth:
            stack.append(identity)
        else:
            stack[depth] = identity
            del stack[depth + 1:]
    return nodes, edges


def registry_identity(package_id):
    if not package_id.startswith("registry+"):
        return None
    package = package_id.rsplit("#", 1)[1]
    name, version = package.rsplit("@", 1)
    return ("registry", name, version)


def unit_features(path, triple):
    """Return exact non-build-script features split by target and exec context."""
    contexts = {"target": {}, "exec": {}}
    dependencies = {"target": {}, "exec": {}}
    units = json.loads(path.read_text())["units"]
    for unit in units:
        identity = registry_identity(unit["pkg_id"])
        if identity is None or "custom-build" in unit["target"]["kind"]:
            continue
        if unit["platform"] == triple:
            context = "target"
        elif unit["platform"] is None:
            context = "exec"
        else:
            raise ValueError("unexpected unit platform %r in %s" % (unit["platform"], path))
        features = tuple(sorted(unit["features"]))
        previous = contexts[context].setdefault(identity, features)
        if previous != features:
            raise ValueError(
                "multiple feature sets for %r in %s context: %r and %r"
                % (identity, context, previous, features)
            )
        deps = []
        for dependency in unit["dependencies"]:
            dependency_unit = units[dependency["index"]]
            dependency_identity = registry_identity(dependency_unit["pkg_id"])
            if dependency_identity is not None and "custom-build" not in dependency_unit["target"]["kind"]:
                deps.append((dependency_identity, dependency["extern_crate_name"]))
        deps = tuple(sorted(deps))
        previous_deps = dependencies[context].setdefault(identity, deps)
        if previous_deps != deps:
            raise ValueError("multiple dependency sets for %r in %s context" % (identity, context))
    return contexts, dependencies


def metadata_aliases(path, source_root):
    metadata = json.loads(path.read_text())
    identities = {}
    for package in metadata["packages"]:
        manifest_dir = Path(package["manifest_path"]).parent
        if package["source"] is None and manifest_dir.is_relative_to(source_root):
            relative = manifest_dir.relative_to(source_root)
            identity = ("path", str(relative) if str(relative) != "." else "")
        elif package["source"] is not None:
            identity = ("registry", package["name"], package["version"])
        else:
            continue
        identities[package["id"]] = identity
    aliases = {}
    proc_macros = {}
    for package in metadata["packages"]:
        identity = identities.get(package["id"])
        if not identity:
            continue
        macro_targets = [
            target["name"]
            for target in package["targets"]
            if "proc-macro" in target["kind"]
        ]
        if macro_targets:
            if len(macro_targets) != 1:
                raise ValueError("proc-macro package has multiple macro targets: %r" % (identity,))
            proc_macros[identity] = macro_targets[0]
    for node in metadata["resolve"]["nodes"]:
        parent = identities.get(node["id"])
        if not parent:
            continue
        for dep in node["deps"]:
            child = identities.get(dep["pkg"])
            if child:
                aliases[(parent, child)] = dep["name"]
    return aliases, proc_macros


def starlark(value, indent=0):
    if isinstance(value, dict):
        lines = ["{"]
        for key in sorted(value):
            lines.append(" " * (indent + 4) + repr(key) + ": " + starlark(value[key], indent + 4) + ",")
        return "\n".join(lines + [" " * indent + "}"])
    if isinstance(value, (list, tuple)):
        return repr(list(value))
    return repr(value)


def target_cfg(triple):
    arch, _, _, env = triple.split("-", 3)
    return 'cfg(all(target_arch = "%s", target_env = "%s", target_os = "linux"))' % (arch, env)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--metadata-dir", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--tree-dir", type=Path, required=True)
    parser.add_argument("--unit-graph-dir", type=Path, required=True)
    args = parser.parse_args()

    products = {}
    registry_packages = {}
    manifests = {}
    unit_feature_audit = {}
    for product in sorted(PRODUCT_FEATURE):
        metadata_product = "profiling" if product == "profiler" else product
        aliases, proc_macros = metadata_aliases(
            args.metadata_dir / metadata_product / "metadata.json",
            args.source_root,
        )
        package_data = {}
        product_registry_packages = {}
        product_unit_features = {}
        target_sections = []
        for triple in TRIPLES:
            nodes, edges = parse_tree(args.tree_dir / ("dd-%s-%s.tree" % (product, triple)), args.source_root)
            contexts, unit_dependencies = unit_features(
                args.unit_graph_dir / ("dd-%s-%s.unit-graph.json" % (product, triple)),
                triple,
            )
            product_registry_packages[triple] = sorted(
                "%s-%s" % (identity[1], identity[2])
                for identity in nodes
                if identity[0] == "registry"
            )
            # Only bridge direct normal dependencies of native path crates.
            # Registry-to-registry build/proc-macro edges remain Cargo/rules_rs
            # edges, preserving resolver-2 execution contexts.
            registry = sorted({
                child
                for parent, child in edges
                if parent[0] == "path" and child[0] == "registry"
            })
            target_sections.append("[target.%s.dependencies]" % repr(target_cfg(triple)))
            for index, identity in enumerate(registry):
                _, name, version = identity
                # Resolve the union so the crate universe contains optional
                # dependencies needed by either Cargo context. The generated
                # rules_rs annotations below select the exact target/exec
                # feature and dependency sets at analysis time.
                if identity not in contexts["target"] and identity not in contexts["exec"]:
                    raise ValueError("direct registry dependency has no Cargo unit: %r" % (identity,))
                features = sorted(
                    set(contexts["target"].get(identity, ())) |
                    set(contexts["exec"].get(identity, ()))
                )
                spec = "package = %s, version = %s, default-features = false" % (repr(name), repr("=" + version))
                if features:
                    spec += ", features = %s" % repr(features)
                target_sections.append("resolved_%d = { %s }" % (index, spec))
            target_sections.append("")

            divergent = {
                "%s-%s" % (identity[1], identity[2]): {
                    "exec": list(contexts["exec"][identity]),
                    "exec_deps": ["%s-%s" % (dep[0][1], dep[0][2]) for dep in unit_dependencies["exec"][identity]],
                    "target": list(contexts["target"][identity]),
                    "target_deps": ["%s-%s" % (dep[0][1], dep[0][2]) for dep in unit_dependencies["target"][identity]],
                }
                for identity in contexts["target"].keys() & contexts["exec"].keys()
                if contexts["target"][identity] != contexts["exec"][identity]
            }
            if product_unit_features and product_unit_features != divergent:
                raise ValueError("target/exec feature divergence varies by triple for %s" % product)
            product_unit_features = divergent

            for identity, features in nodes.items():
                if identity[0] != "path":
                    continue
                package = identity[1]
                data = package_data.setdefault(
                    package,
                    {"features": {}, "deps": {}, "proc_macro_deps": {}, "aliases": {}},
                )
                data["features"][triple] = sorted(features)
                children = sorted(child for parent, child in edges if parent == identity)
                deps = []
                proc_macro_deps = []
                alias_map = {}
                for child in children:
                    if child[0] == "path":
                        label = "//" + child[1]
                    else:
                        label = child[1] + "-" + child[2]
                    alias = aliases[(identity, child)]
                    if child in proc_macros:
                        # rules_rust's aliases attribute is target-configured.
                        # A proc-macro key there creates an unused target
                        # variant beside the cfg=exec dependency. Cargo's
                        # unrenamed macro names need no alias entry.
                        if alias != proc_macros[child]:
                            raise ValueError(
                                "renamed proc macro needs an exec-configured alias adapter: %r -> %s"
                                % (child, alias)
                            )
                        proc_macro_deps.append(label)
                    else:
                        deps.append(label)
                        alias_map[label] = alias
                data["deps"][triple] = deps
                data["proc_macro_deps"][triple] = proc_macro_deps
                data["aliases"][triple] = alias_map
        products[product] = package_data
        registry_packages[product] = product_registry_packages
        unit_feature_audit[product] = product_unit_features
        unknown_replacements = REPLACED_EXEC_UNITS[product].keys() - product_unit_features.keys()
        if unknown_replacements:
            raise ValueError("replacement exemptions are not divergent Cargo units: %r" % sorted(unknown_replacements))
        manifests[product] = """[package]
name = "ddtrace-%s-registry-resolution"
version = "0.0.0"
edition = "2021"
publish = false

[workspace]

%s""" % (product, "\n".join(target_sections))

    args.output_dir.mkdir(parents=True, exist_ok=True)
    (args.output_dir / "product_graphs.bzl").write_text(
        '"""Generated by generate_product_graph.py; do not edit."""\n\nPRODUCT_GRAPHS = '
        + starlark(products)
        + "\n\nPRODUCT_REGISTRY_PACKAGES = "
        + starlark(registry_packages)
        + "\n\nPRODUCT_UNIT_FEATURES = "
        + starlark(unit_feature_audit)
        + "\n\nPRODUCT_REPLACED_EXEC_UNITS = "
        + starlark(REPLACED_EXEC_UNITS)
        + "\n",
    )
    for product, manifest in manifests.items():
        (args.output_dir / (product + "-resolution.Cargo.toml")).write_text(manifest)


if __name__ == "__main__":
    main()
