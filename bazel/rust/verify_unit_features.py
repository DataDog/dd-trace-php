#!/usr/bin/env python3
"""Compare configured Bazel Rustc features and dependencies with Cargo units."""

import argparse
import ast
import json
import re
from pathlib import Path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--aquery", type=Path, required=True)
    parser.add_argument("--product", choices=("profiler", "tracer"), required=True)
    parser.add_argument("--product-graphs", type=Path, required=True)
    args = parser.parse_args()

    generated = args.product_graphs.read_text()
    expected_text, replacements_text = generated.split("PRODUCT_UNIT_FEATURES = ", 1)[1].split(
        "PRODUCT_REPLACED_EXEC_UNITS = ", 1
    )
    expected = ast.literal_eval(expected_text)[args.product]
    replacements = ast.literal_eval(replacements_text)[args.product]
    aquery = json.loads(args.aquery.read_text())
    labels = {target["id"]: target["label"] for target in aquery["targets"]}
    is_tool = {
        configuration["id"]: configuration.get("isTool", False)
        for configuration in aquery["configuration"]
    }

    actual = {}
    hub = "ddtrace_%s_crates" % args.product
    for action in aquery["actions"]:
        label = labels.get(action.get("targetId"), "")
        match = re.search(r"rules_rs\+\+crate\+%s__(.+)//:" % hub, label)
        if not match:
            continue
        package = match.group(1)
        if package not in expected:
            continue
        arguments = action.get("arguments", [])
        joined = " ".join(arguments)
        if "--crate-name=build_script_build" in joined:
            continue
        context = "exec" if is_tool[action["configurationId"]] else "target"
        features = tuple(sorted(re.findall(r'feature="([^"]+)"', joined)))
        deps = tuple(sorted(set(re.findall(
            r"--extern=[^=]+=\S*rules_rs\+\+crate\+%s__([^/]+)/" % hub,
            joined,
        ))))
        actual.setdefault(package, {}).setdefault(context, set()).add((features, deps))

    errors = []
    for package, contexts in sorted(expected.items()):
        for context in ("target", "exec"):
            wanted = tuple(contexts[context])
            wanted_deps = tuple(contexts[context + "_deps"])
            found = actual.get(package, {}).get(context, set())
            if context == "exec" and not found and package in replacements:
                continue
            if found != {(wanted, wanted_deps)}:
                errors.append(
                    "%s %s: expected features=%r deps=%r, got %r"
                    % (package, context, wanted, wanted_deps, sorted(found))
                )
    if errors:
        raise SystemExit("Cargo/Bazel unit mismatch:\n" + "\n".join(errors))
    print(
        "verified exact features/deps for %d divergent %s package identities in target/exec contexts (%d replaced build-script exec units declared)"
        % (len(expected), args.product, len(replacements))
    )


if __name__ == "__main__":
    main()
