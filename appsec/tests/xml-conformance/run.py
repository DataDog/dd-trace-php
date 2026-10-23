# /// script
# requires-python = ">=3.11"
# ///
"""Compare XML body parsers with the preserved XML review corpus.

xmloxide supports SAX, DOM and recovery modes. anyxml and the current
worktree's parser expose the raw body SAX API, excluding HTTP charset policy.
Run --parser current separately to obtain a baseline over the same cases.
"""

import argparse
import concurrent.futures
import json
import pathlib
import re
import subprocess
from collections import Counter, defaultdict

HERE = pathlib.Path(__file__).resolve().parent
MODES = {
    "sax": [],
    "dom": ["--dom"],
    "sax-recover": ["--recover"],
    "dom-recover": ["--dom", "--recover"],
}
PARSERS = {
    "xmloxide": ("xmloxide", "0.5.0"),
    "anyxml": ("anyxml", "0.15.1"),
    "current": ("current", "current-worktree"),
}


def cases():
    for line in (HERE / "cases.jsonl").open():
        case = json.loads(line)
        encoded = case["xml"]
        field = "xml" if encoded["encoding"] == "utf-8" else "xml_base64"
        origin = case["origin_test"]
        wrapper = "content_type_charset" in origin or "iso88591" in origin
        yield {
            "id": case["id"],
            "group": case["authority"],
            "origin": origin,
            "wrapper_case": wrapper,
            "input": {
                "name": case["id"],
                field: encoded["data"],
                "max_depth": case["max_depth"],
            },
            "expected_output": case["expected"]["value"],
        }
    for case in json.loads((HERE / "outstanding.json").read_text()):
        for depth in case.get("max_depths", [30]):
            name = f"outstanding/{case['name']}@depth={depth}"
            yield {
                "id": name,
                "group": "outstanding_specification_case",
                "origin": case["category"],
                "wrapper_case": False,
                "input": {"name": name, "xml": case["xml"], "max_depth": depth},
                "spec_expected_accept": case["spec_expected_accept"],
                # These expectations describe the default recording depth;
                # the corpus has no separate output oracle for depth one.
                "expected_output_if_known": (
                    case.get("expected_output_if_known") if depth == 30 else None
                ),
                "reference_acceptance": {
                    key: value["accepted"] for key, value in case["observed"].items()
                },
            }


def evaluate(binary, mode, case, timeout, parser="xmloxide"):
    command = [str(binary), *MODES[mode]]
    if parser == "current":
        command.append("--current")
    try:
        process = subprocess.run(
            command,
            input=json.dumps(case["input"]) + "\n",
            text=True,
            capture_output=True,
            timeout=timeout,
        )
        if process.returncode:
            actual = {"process_error": process.returncode, "stderr": process.stderr}
        else:
            actual = json.loads(process.stdout)
    except subprocess.TimeoutExpired:
        actual = {"timeout_seconds": timeout}
    except (ValueError, OSError) as error:
        actual = {"runner_error": str(error)}
    record = {key: value for key, value in case.items() if key != "input"}
    record.update(mode=mode, actual=actual)
    # HTTP charset policy belongs to the PHP wrapper. Native parser output is
    # still recorded for those cases, but it is not a wrapper compatibility test.
    if "expected_output" in case and not case["wrapper_case"]:
        record["output_matches"] = (
            "output" in actual and actual["output"] == case["expected_output"]
        )
    if case.get("expected_output_if_known") is not None:
        record["known_output_matches"] = (
            "output" in actual
            and actual["output"] == case["expected_output_if_known"]
        )
    # Recovery returning a tree is not a claim of well-formedness.
    if "spec_expected_accept" in case and not mode.endswith("-recover"):
        record["acceptance_matches_spec"] = (
            actual.get("accepted") is case["spec_expected_accept"]
        )
    return record


def summarize(records):
    summary = defaultdict(Counter)
    groups = defaultdict(lambda: defaultdict(Counter))
    for record in records:
        counts = summary[record["mode"]]
        counts["cases"] += 1
        actual = record["actual"]
        if not isinstance(actual.get("accepted"), bool):
            counts["execution_failures"] += 1
        if record["wrapper_case"]:
            counts["wrapper_cases_recorded_only"] += 1
        if "output_matches" in record:
            counts["output_comparisons"] += 1
            counts["output_matches"] += record["output_matches"]
            group = groups[record["mode"]][record["group"]]
            group["comparisons"] += 1
            group["matches"] += record["output_matches"]
        if "acceptance_matches_spec" in record:
            counts["spec_acceptance_comparisons"] += 1
            counts["spec_acceptance_matches"] += record["acceptance_matches_spec"]
        if "known_output_matches" in record:
            counts["outstanding_known_output_comparisons"] += 1
            counts["outstanding_known_output_matches"] += record["known_output_matches"]
    return {"modes": dict(summary), "output_by_authority": dict(groups)}


def main():
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--parser", choices=PARSERS, default="xmloxide")
    cli.add_argument("--binary", type=pathlib.Path)
    cli.add_argument("--output", type=pathlib.Path, default=HERE / "results")
    cli.add_argument("--modes", nargs="+", choices=MODES)
    cli.add_argument("--case", default=".", help="Regex selecting case IDs")
    cli.add_argument("--timeout", type=float, default=10)
    cli.add_argument("--workers", type=int, default=4)
    args = cli.parse_args()
    if args.modes is None:
        args.modes = list(MODES) if args.parser == "xmloxide" else ["sax"]
    if args.parser != "xmloxide" and any(mode != "sax" for mode in args.modes):
        cli.error(f"--parser {args.parser} supports only --modes sax")
    binary = args.binary
    if binary is None:
        harness = "xmloxide" if args.parser == "xmloxide" else "anyxml"
        if harness == "anyxml":
            subprocess.run(
                ["uv", "run", str(HERE / harness / "prepare.py")], check=True
            )
        subprocess.run(
            [
                "cargo",
                "build",
                "--release",
                "--locked",
                "--manifest-path",
                str(HERE / harness / "Cargo.toml"),
                "--target-dir",
                str(HERE / harness / "target"),
            ],
            check=True,
        )
        binary = HERE / harness / "target" / "release" / f"{harness}-body-harness"
    selected = [case for case in cases() if re.search(args.case, case["id"])]
    if not selected:
        cli.error("No cases selected")
    jobs = [(mode, case) for mode in args.modes for case in selected]
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
        records = list(
            executor.map(
                lambda job: evaluate(
                    binary.resolve(), *job, args.timeout, args.parser
                ),
                jobs,
            )
        )
    args.output.mkdir(parents=True, exist_ok=True)
    with (args.output / "results.jsonl").open("w") as output:
        for record in records:
            output.write(json.dumps(record) + "\n")
    summary = summarize(records)
    library, version = PARSERS[args.parser]
    summary.update(
        library=library, version=version, distinct_case_runs=len(selected)
    )
    if args.parser == "anyxml":
        summary["patches"] = [
            "internal-parameter-entities",
            "resource-limits",
            "standalone-whitespace",
            "skipped-dtd-entities",
            "character-reference-events",
        ]
    formatted = json.dumps(summary, indent=2) + "\n"
    (args.output / "summary.json").write_text(formatted)
    print(formatted, end="")


if __name__ == "__main__":
    main()
