#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = []
# ///
"""A/B-compare two prebuilt sgc_bench binaries (e.g. two crate revisions).

Either side may be this directory's C++ program (C API) or the Rust bench
in ../benches (Rust API); both print the same table and JSON report. The
two binaries run interleaved, alternating which one goes first in each
round (A B, B A, A B, ...), so slow drift (thermal, background load)
affects both equally. Each run reports, per scenario, the median of its
internal repetitions; the speedup for a scenario is the median over rounds
of the paired ratio cur/ref. Noise is estimated from the spread of those
paired ratios (MAD-based, as a relative standard error), and a verdict is
given only when the change clears both that noise and a minimum effect.

    uv run ab_bins.py --ref-bin base/sgc_bench --cur-bin head/sgc_bench
    uv run ab_bins.py --ref-bin A --cur-bin A --quick       # A/A noise floor
    uv run ab_bins.py ... --bench-args=--no-huge-pages       # Linux (note the =)

"speedup" is cur/ref throughput: < 1.0 means cur is slower.
"""

from __future__ import annotations

import argparse
import json
import math
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
from pathlib import Path


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--ref-bin", required=True)
    p.add_argument("--cur-bin", required=True)
    p.add_argument("--rounds", type=int)
    p.add_argument("--quick", action="store_true")
    p.add_argument("--filter")
    p.add_argument("--threads")
    p.add_argument("--bench-args", default="")
    p.add_argument("--threshold", type=float, default=2.0)
    p.add_argument("--json")
    p.add_argument("--keep", action="store_true")
    args = p.parse_args()
    if args.rounds is None:
        args.rounds = 3 if args.quick else 5
    args.ref = f"{args.ref_bin} (ref)"
    work = Path(tempfile.mkdtemp(prefix="sgc-abbins-"))
    try:
        bins = {"ref": Path(args.ref_bin).resolve(),
                "cur": Path(args.cur_bin).resolve()}
        print(f"ref  {bins['ref']}\ncur  {bins['cur']}")
        runs = run_interleaved(bins, args, work)
        summary = summarize(runs, args)
        if args.json:
            Path(args.json).write_text(json.dumps(summary, indent=1))
    finally:
        if args.keep:
            print(f"kept {work}")
        else:
            shutil.rmtree(work, ignore_errors=True)
    return 0


def run_interleaved(binaries: dict[str, Path], args: argparse.Namespace,
                    work: Path) -> dict:
    extra = ["--no-model", *args.bench_args.split()]
    if args.quick:
        extra.append("--quick")
    if args.filter:
        extra += ["--filter", args.filter]
    if args.threads:
        extra += ["--threads", args.threads]
    runs: dict = {"ref": [], "cur": []}
    reps = 0
    t0 = time.monotonic()
    for rnd in range(args.rounds):
        order = ["ref", "cur"] if rnd % 2 == 0 else ["cur", "ref"]
        for side in order:
            out = work / f"{side}-{rnd}.json"
            r = subprocess.run([str(binaries[side]), "--json", str(out),
                                *extra], capture_output=True, text=True)
            if r.returncode != 0:
                sys.stderr.write(r.stdout + r.stderr)
                raise SystemExit(f"{side} run {rnd} failed")
            doc = json.loads(out.read_text())
            reps = doc["config"]["reps"]
            runs[side].append({x["name"]: x for x in doc["results"]})
            print(f"round {rnd + 1}/{args.rounds} {side} done "
                  f"({time.monotonic() - t0:.0f}s)", flush=True)
    runs["reps"] = reps
    return runs


def mad_sigma(values: list[float]) -> float:
    med = statistics.median(values)
    return 1.4826 * statistics.median(abs(v - med) for v in values)


def summarize(runs: dict, args: argparse.Namespace) -> dict:
    rounds = len(runs["ref"])
    names = [n for n in runs["ref"][0] if all(n in r for r in runs["cur"])]
    if not names:
        raise SystemExit("no scenario ran on both sides")
    rows = []
    for name in names:
        ref = [r[name]["median_mops"] for r in runs["ref"]]
        cur = [r[name]["median_mops"] for r in runs["cur"]]
        logs = [math.log(c / b) for c, b in zip(cur, ref)]
        n = len(logs)
        med = statistics.median(logs)
        # Three noise sources, each a floor for the others, because a
        # handful of rounds can make any single MAD zero by chance:
        #  - the spread of the paired ratios themselves;
        #  - run-to-run spread of each binary on its own (thread placement
        #    across core clusters, mapping placement), pooled over both;
        #  - the runs' own repetition spread (MAD%), as the standard error
        #    of a median of `reps` repetitions.
        # The latter two estimate one side's noise; a ratio carries both.
        run_dev = [math.log(v) - statistics.median(math.log(x) for x in side)
                   for side in (ref, cur) for v in side]
        run_sigma = 1.4826 * statistics.median(abs(d) for d in run_dev)
        reps = runs["reps"]
        rep_sigma = 1.2533 / math.sqrt(reps) * 1.4826 * statistics.median(
            [r[name]["mad_pct"] / 100 for r in runs["ref"] + runs["cur"]])
        sigma = max(mad_sigma(logs), math.sqrt(2) * run_sigma,
                    math.sqrt(2) * rep_sigma)
        stderr = 1.2533 * sigma / math.sqrt(n)  # of a median
        # All rounds but at most one must agree on the direction.
        agree = sum(1 for x in logs if (x > 0) == (med > 0)) >= max(n - 1, 3)
        effect = math.expm1(med) * 100
        # ~99% half-width: with 30 scenarios per comparison, a 95% band
        # flags one or two scenarios in every A/A run.
        noise = math.expm1(2.6 * stderr) * 100
        if abs(effect) >= max(args.threshold, noise) and agree:
            verdict = "FASTER" if med > 0 else "SLOWER"
        else:
            verdict = "~"
        rows.append({
            "name": name,
            "ref_mops": statistics.median(ref),
            "cur_mops": statistics.median(cur),
            "speedup": math.exp(med),
            "effect_pct": effect,
            "noise_pct": noise,
            "min_ratio": math.exp(min(logs)),
            "max_ratio": math.exp(max(logs)),
            "verdict": verdict,
        })

    print()
    print(f"{'scenario':<26} {'ref Mops':>9} {'cur Mops':>9} {'speedup':>8} "
          f"{'+/-99%':>7} {'[min - max ratio]':>17}  verdict")
    for r in rows:
        print(f"{r['name']:<26} {r['ref_mops']:9.2f} {r['cur_mops']:9.2f} "
              f"{r['speedup']:8.3f} {r['noise_pct']:6.1f}% "
              f"{r['min_ratio']:8.3f} - {r['max_ratio']:6.3f}  {r['verdict']}")

    groups: dict[str, list[float]] = {}
    for r in rows:
        groups.setdefault(r["name"].split("/")[0], []).append(r["speedup"])
    all_speedups = [r["speedup"] for r in rows]
    print()
    for g, sp in [*groups.items(), ("ALL", all_speedups)]:
        geo = math.exp(statistics.fmean(math.log(s) for s in sp))
        print(f"geomean {g:<12} {geo:.3f}  ({len(sp)} scenarios)")
    faster = sum(r["verdict"] == "FASTER" for r in rows)
    slower = sum(r["verdict"] == "SLOWER" for r in rows)
    print(f"\n{faster} faster, {slower} slower, {len(rows) - faster - slower} "
          f"within noise (threshold {args.threshold}%, {rounds} rounds)")
    return {"ref": args.ref, "rounds": rounds, "scenarios": rows,
            "raw": runs,
            "geomean": {g: math.exp(statistics.fmean(math.log(s) for s in sp))
                        for g, sp in [*groups.items(), ("ALL", all_speedups)]}}


if __name__ == "__main__":
    sys.exit(main())
