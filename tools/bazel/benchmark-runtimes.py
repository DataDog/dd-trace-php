#!/usr/bin/env python3
"""Measure RBE without conflating repository fetch, cache reuse and execution.

All output bases and servers are retained. Use a disk with room for the full
matrix, and run --mode=prefetch before timing. Repeat forced representative runs
at --jobs=10/25/50; `--summarize` selects the lowest within 5% of the best median.
"""

import argparse
from collections import defaultdict
from datetime import datetime, timezone
import gzip
import hashlib
import importlib.util
import itertools
import json
import os
from pathlib import Path
import statistics
import subprocess
import time

_spec = importlib.util.spec_from_file_location("runtime_log_metrics", Path(__file__).with_name("runtime-log-metrics.py"))
_metrics = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_metrics)


def run(command, log, stderr=None):
    with log.open("wb") as output:
        if stderr:
            with stderr.open("wb") as errors:
                return subprocess.run(command, stdout=output, stderr=errors).returncode
        return subprocess.run(command, stdout=output, stderr=subprocess.STDOUT).returncode


def source_hashes(root):
    files = subprocess.check_output(["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"], cwd=root).split(b"\0")
    return {os.fsdecode(name): hashlib.sha256((root / os.fsdecode(name)).read_bytes()).hexdigest()
            for name in files if name and (root / os.fsdecode(name)).is_file()}


def profile_metrics(path):
    # Duration sums represent aggregate worker/client cost, not wall time.
    # Keep categories intact: queueing must never count as execution cost.
    totals = defaultdict(float)
    counts = defaultdict(int)
    # Bazel 9.2 writes one trace event per line. Full matrix profiles expand
    # to gigabytes, so retain only category totals instead of the whole trace.
    with gzip.open(path, "rt") as stream:
        header = stream.readline()
        prefix, separator, first_event = header.partition('"traceEvents":[')
        if not separator:
            raise ValueError("Not a Bazel 9.2 JSON trace: " + str(path))
        for line in itertools.chain([first_event], stream):
            line = line.strip()
            if not line:
                continue
            if line.startswith("]"):
                break
            event = json.loads(line.removesuffix(","))
            if event.get("ph") == "X" and "dur" in event:
                category = event.get("cat", "uncategorized")
                totals[category] += event["dur"] / 1_000_000
                counts[category] += 1
        else:
            raise ValueError("Truncated Bazel profile: " + str(path))
    return {k: dict(aggregate_seconds=v, events=counts[k]) for k, v in sorted(totals.items())}


def summarize(paths):
    groups = defaultdict(lambda: defaultdict(list))
    for path in paths:
        result = json.loads(path.read_text())
        if result["exit_code"] or not result.get("source_snapshot_stable", True):
            continue
        key = (tuple(result["targets"]), result["arch"], result["mode"], result["source_digest"], tuple(result.get("comparison_flags", [])))
        groups[key][result["jobs"]].append(result["elapsed_seconds"])
    reports = []
    for (targets, arch, mode, digest, flags), samples in groups.items():
        medians = {jobs: statistics.median(values) for jobs, values in samples.items()}
        best = min(medians.values())
        complete = all(len(samples.get(jobs, [])) >= 3 for jobs in [10, 25, 50])
        reports.append(dict(targets=targets, arch=arch, mode=mode, source_digest=digest, comparison_flags=flags,
                            samples={jobs: len(values) for jobs, values in samples.items()}, medians=medians,
                            selected_jobs=min(j for j, median in medians.items() if median <= best * 1.05) if complete else None,
                            comparison_complete=complete))
    print(json.dumps(reports, indent=2))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--bazel", default="build/bin/bb")
    parser.add_argument("--root", type=Path, help="Persistent benchmark directory on a volume with sufficient free space")
    parser.add_argument("--arch", choices=["amd64", "arm64"], default="amd64")
    parser.add_argument("--jobs", type=int, choices=[10, 25, 50], default=50)
    parser.add_argument("--mode", choices=["prefetch", "forced", "cached", "noop", "c-edit", "rust-edit"])
    parser.add_argument("--edit", type=Path, help="Source to append a harmless comment to; restored in finally")
    parser.add_argument("--summarize", nargs="+", type=Path)
    parser.add_argument("--flag", action="append", default=[], help="Additional Bazel option, e.g. --flag=--repository_cache=PATH")
    parser.add_argument("targets", nargs="*")
    args = parser.parse_args()
    if args.summarize:
        summarize(args.summarize)
        return
    if not args.root or not args.mode or not args.targets:
        parser.error("--root, --mode and at least one target are required")
    if args.mode.endswith("-edit") and not args.edit:
        parser.error("edit modes require --edit")
    workspace = Path.cwd()
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S.%fZ")
    record = args.root.resolve() / "results" / (args.arch + "-" + args.mode + "-j" + str(args.jobs) + "-" + stamp)
    record.mkdir(parents=True)
    fresh = args.mode in ["forced", "cached"]
    output_base = args.root.resolve() / "outputs" / (record.name if fresh else args.arch + "-iteration")
    bazel = [str(Path(args.bazel).resolve()), "--output_base=" + str(output_base)]
    flags = ["--config=remote-hermetic-" + args.arch, "--jobs=" + str(args.jobs), "--build_tag_filters=", "--bes_backend=", "--bes_results_url="] + args.flag
    if args.mode == "prefetch":
        command = bazel + ["build", "--nobuild"] + flags + args.targets
        (record / "command.json").write_text(json.dumps(command, indent=2))
        raise SystemExit(run(command, record / "prefetch.log"))
    flags += ["--repository_disable_download", "--config=rbe-forced" if args.mode == "forced" else "--config=rbe-cached"]
    # Resolve repositories and toolchains before timing. Fresh modes still
    # have no action cache, outputs, or prior execution in their new base.
    status = run(bazel + ["build", "--nobuild"] + flags + args.targets, record / "prepare.log")
    if status:
        raise SystemExit(status)
    original = None
    edited = None
    if args.mode in ["noop", "c-edit", "rust-edit"]:
        status = run(bazel + ["build"] + flags + args.targets, record / "warmup.log")
        if status:
            raise SystemExit(status)
    try:
        if args.mode.endswith("-edit"):
            original = args.edit.read_bytes()
            edited = original + ("\n// Bazel incremental measurement " + stamp + "\n").encode()
            args.edit.write_bytes(edited)
        hashes = source_hashes(workspace)
        (record / "sources.json").write_text(json.dumps(hashes, sort_keys=True, indent=2))
        (record / "workspace.diff").write_bytes(subprocess.check_output(["git", "diff", "--binary"]))
        command = bazel + ["build"] + flags + [
            "--profile=" + str(record / "profile.json.gz"),
            "--execution_log_compact_file=" + str(record / "execution.pb.zstd"),
            "--build_event_json_file=" + str(record / "events.jsonl"),
            "--remote_grpc_log=" + str(record / "remote-grpc.pb"),
        ] + args.targets
        (record / "command.json").write_text(json.dumps(command, indent=2))
        start = time.monotonic()
        status = run(command, record / "build.log")
        elapsed = time.monotonic() - start
        result = dict(mode=args.mode, arch=args.arch, jobs=args.jobs, targets=args.targets, comparison_flags=args.flag,
                      elapsed_seconds=elapsed, exit_code=status, output_base=str(output_base),
                      source_snapshot_stable=hashes == source_hashes(workspace),
                      source_digest=hashlib.sha256(json.dumps(hashes, sort_keys=True).encode()).hexdigest())
        profile = record / "profile.json.gz"
        if profile.exists():
            result["profile_categories"] = profile_metrics(profile)
        execution_log = record / "execution.pb.zstd"
        if execution_log.exists():
            result["execution"] = _metrics.execution_metrics(execution_log)
        grpc_log = record / "remote-grpc.pb"
        if grpc_log.exists():
            result["transfers"] = _metrics.transfer_metrics(grpc_log)
        events = record / "events.jsonl"
        if events.exists():
            for line in events.read_text().splitlines():
                event = json.loads(line)
                if "buildMetrics" in event:
                    result["build_metrics"] = event["buildMetrics"]
                if "optionsParsed" in event:
                    result["effective_flags"] = event["optionsParsed"]
        query_status = run(bazel + ["aquery"] + flags + ["--output=jsonproto", "deps(set(%s))" % " ".join(args.targets)], record / "actions.json", record / "aquery.log")
        result["aquery_exit_code"] = query_status
        (record / "result.json").write_text(json.dumps(result, sort_keys=True, indent=2) + "\n")
        print(record / "result.json")
        raise SystemExit(status or query_status)
    finally:
        if original is not None:
            if args.edit.read_bytes() != edited:
                raise RuntimeError("Source changed concurrently; refusing to overwrite it: " + str(args.edit))
            args.edit.write_bytes(original)


if __name__ == "__main__":
    main()
