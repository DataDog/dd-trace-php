# /// script
# requires-python = ">=3.11"
# dependencies = ["matplotlib==3.10.7"]
# ///
"""Performance figures of the README.

  uv run plot.py aggregate --machine 9950x --label "..." --boundary 8.5 \
      --boundary-label "CCD 1" --cross-event ls_any_fills_from_sys.near_cache \
      --cross-label "Fills from the other CCD" \
      --runs 'raw/r*.json' --perf 'raw/perfstat/*.csv' > data/9950x.json
  uv run plot.py plot data/9950x.json   # writes 9950x-*-{light,dark}.svg

`aggregate` keeps, per scenario and thread count, the throughput of every run
(each run's value is its median of repetitions), and per-operation hardware
counters of the timed region: perf stat over a run with 7 repetitions minus
one with 1 repetition, divided by the 6 x 250000 x threads operations
between them.
"""

from __future__ import annotations

import argparse
import csv
import glob
import json
import os
import re
import statistics as st
import sys
from collections import defaultdict
from pathlib import Path

# Slot order: lookup_hit is third in both READS and COUNTED, so it keeps its
# color across figures, as insert_new and mixed/64Ki do in WRITES and COUNTED.
READS = ["lookup_miss/64Ki/s0.8", "mixed/16Ki/s0.8", "lookup_hit/32Ki/s0.8"]
WRITES = ["insert_new/1Mi", "mixed/64Ki/s0.8", "mixed/1Mi/s0.8"]
COUNTED = ["insert_new/1Mi", "mixed/64Ki/s0.8", "lookup_hit/32Ki/s0.8"]
OPS_PER_REP = 250000
TIMED_REPS = 6  # 7 - 1


def aggregate(args: argparse.Namespace) -> None:
    runs: dict[str, dict[int, list[float]]] = defaultdict(lambda: defaultdict(list))
    errors = 0
    for path in sorted(glob.glob(args.runs)):
        for r in json.load(open(path))["results"]:
            family, threads = r["name"].rsplit("/t", 1)
            runs[family][int(threads)].append(r["median_mops"])
            errors += r["lookup_errors"] + r["insert_errors"]
    counters: dict[str, dict[str, dict[int, dict[int, list[float]]]]] = defaultdict(
        lambda: defaultdict(lambda: defaultdict(lambda: defaultdict(list))))
    scenarios = {f.replace("/", "_"): f for f in runs}
    for path in glob.glob(args.perf or ""):
        m = re.match(r"(.+)-t(\d+)-reps(\d+)-\d+\.csv", os.path.basename(path))
        scen = scenarios.get(m[1], m[1])
        for row in csv.reader(open(path)):
            if len(row) < 3 or not row[0].strip() or row[0].startswith("#"):
                continue
            try:
                value = float(row[0])
            except ValueError:
                continue
            event = re.sub(r"^cpu/|/u?$|:u$", "", row[2])
            counters[scen][event][int(m[2])][int(m[3])].append(value)
    per_op: dict[str, dict[str, dict[str, list[float]]]] = {}
    for scen, events in counters.items():
        for event, by_threads in events.items():
            for t, by_reps in by_threads.items():
                if 7 not in by_reps or 1 not in by_reps:
                    continue
                ops = TIMED_REPS * OPS_PER_REP * t
                v7, v1 = by_reps[7], by_reps[1]
                per_op.setdefault(scen, {}).setdefault(event, {})[str(t)] = [
                    (st.median(v7) - st.median(v1)) / ops,
                    (min(v7) - max(v1)) / ops,
                    (max(v7) - min(v1)) / ops,
                ]
    json.dump({
        "machine": args.machine,
        "label": args.label,
        "boundary": args.boundary,
        "boundary_label": args.boundary_label,
        "cross_event": args.cross_event,
        "cross_label": args.cross_label,
        "errors": errors,
        "runs": {f: {str(t): v for t, v in sorted(ts.items())} for f, ts in sorted(runs.items())},
        "counters": per_op,
    }, sys.stdout, indent=1)
    sys.stdout.write("\n")


# --- Figures ---------------------------------------------------------------

# Reference palette slots 1-3 (blue, orange, aqua), stepped per theme; text
# and grid follow GitHub's light and dark surfaces.
THEMES = {
    "light": {"series": ["#2a78d6", "#eb6834", "#1baf7a"], "text": "#1f2328",
              "muted": "#59636e", "grid": "#d1d9e0", "zone": "#818b98"},
    "dark": {"series": ["#3987e5", "#d95926", "#199e70"], "text": "#f0f6fc",
             "muted": "#9198a1", "grid": "#3d444d", "zone": "#9198a1"},
}


def draw(ax, theme: dict, title: str, ylabel: str,
         series: list[tuple[str, list[tuple[float, float, float, float]]]],
         xticks: list[int], xmax: float, boundary: float, boundary_label: str,
         ref: float | None = None) -> None:
    """One panel: series = [(name, [(threads, median, low, high), ...])]."""
    ax.axvspan(boundary, xmax, color=theme["zone"], alpha=0.08, lw=0)
    if ref is not None:
        ax.axhline(ref, color=theme["muted"], lw=1, ls=(0, (4, 3)))
    ends = []
    for color, (name, pts) in zip(theme["series"], series):
        x = [p[0] for p in pts]
        ax.fill_between(x, [p[2] for p in pts], [p[3] for p in pts], color=color, alpha=0.2, lw=0)
        ax.plot(x, [p[1] for p in pts], color=color, lw=2, marker="o", ms=3.5)
        ends.append([pts[-1][1], name, color])
    ax.set_xlim(1, xmax)
    ax.set_ylim(bottom=0)
    ax.set_xticks(xticks)
    ax.set_title(title, loc="left", fontsize=11, fontweight="bold", color=theme["text"])
    ax.set_xlabel("threads", color=theme["muted"])
    ax.set_ylabel(ylabel, color=theme["muted"])
    ax.grid(axis="y", color=theme["grid"], lw=0.8)
    ax.set_axisbelow(True)
    for side in ("top", "right", "left"):
        ax.spines[side].set_visible(False)
    ax.spines["bottom"].set_color(theme["muted"])
    ax.tick_params(colors=theme["muted"], length=3)
    ax.tick_params(axis="y", length=0)
    top = ax.get_ylim()[1]
    ax.text(boundary + 0.3, top * (0.04 if ref is not None else 0.96), f"{boundary_label} →",
            color=theme["muted"], fontsize=8.5, va="bottom" if ref is not None else "top")
    # Direct labels at the line ends, pushed apart so they do not overlap.
    ends.sort()
    gap = top * 0.07
    for k in range(1, len(ends)):
        ends[k][0] = max(ends[k][0], ends[k - 1][0] + gap)
    # Keep the stack inside the plot: shift it down if it overflows the top.
    overflow = ends[-1][0] - top * 0.97 if ends else 0
    if overflow > 0:
        for e in ends:
            e[0] -= overflow
    for y, name, color in ends:
        ax.annotate(name, xy=(1.045, y), xycoords=("axes fraction", "data"), color=theme["text"],
                    fontsize=8.5, va="center",
                    bbox={"boxstyle": "square,pad=0", "fc": "none", "ec": "none"})
        ax.plot([1.02], [y], marker="s", ms=5, color=color, transform=ax.get_yaxis_transform(),
                clip_on=False)


def figure(path: Path, panels: list[tuple], desc: str) -> None:
    import matplotlib
    matplotlib.use("svg")
    import matplotlib.pyplot as plt
    plt.rcParams.update({"svg.fonttype": "none", "font.family": "sans-serif",
                         "font.sans-serif": ["DejaVu Sans"], "svg.hashsalt": "sgc"})
    for name, theme in THEMES.items():
        fig, axes = plt.subplots(1, len(panels), figsize=(4.6 * len(panels), 3.1))
        for ax, args in zip(axes, panels):
            draw(ax, theme, *args)
        fig.subplots_adjust(left=0.07, right=0.84, wspace=0.62, top=0.88, bottom=0.16)
        fig.savefig(path.with_name(f"{path.stem}-{name}.svg"), transparent=True,
                    metadata={"Title": desc, "Date": None, "Creator": None})
        plt.close(fig)


def plot(args: argparse.Namespace) -> None:
    data = json.load(open(args.data))
    outdir = Path(args.data).resolve().parent.parent
    m, b, bl = data["machine"], data["boundary"], data["boundary_label"]
    threads = sorted({int(t) for f in data["runs"].values() for t in f})
    xmax = max(threads)
    xticks = [t for t in threads if t in (1, 4, 8, 12, 16, 20, 24, 32, 43)]

    def rows(f: str):
        return sorted(data["runs"][f].items(), key=lambda kv: int(kv[0]))

    def series(fams: list[str], scale):
        """(median, min, max) of every family's runs, each passed through
        scale(family, threads, value)."""
        return [(f, [(int(t), *(scale(f, int(t), x) for x in (st.median(v), min(v), max(v))))
                     for t, v in rows(f)]) for f in fams]

    def throughput(fams: list[str]):
        return series(fams, lambda f, t, x: x)

    def efficiency(fams: list[str]):
        return series(fams, lambda f, t, x: x / t / st.median(data["runs"][f]["1"]))

    label = data["label"]
    figure(outdir / f"{m}-throughput.svg", [
        ("Read-mostly", "Mops/s (all threads)", throughput(READS), xticks, xmax, b, bl),
        ("Write-heavy", "Mops/s (all threads)", throughput(WRITES), xticks, xmax, b, bl),
    ], f"Aggregate throughput against pinned threads on {label}")
    figure(outdir / f"{m}-scaling.svg", [
        ("Read-mostly", "per-thread rate / 1 thread", efficiency(READS), xticks, xmax, b, bl, 1.0),
        ("Write-heavy", "per-thread rate / 1 thread", efficiency(WRITES), xticks, xmax, b, bl, 1.0),
    ], f"Per-thread throughput relative to one thread on {label}")
    c = data["counters"]
    if c:
        def counter(event: str):
            # Counts are differences of two runs, so a true zero can come out
            # slightly negative; clamp at 0.
            return [(f, [(int(t), *(max(0.0, v) for v in c[f][event][t]))
                         for t in sorted(c[f][event], key=int)])
                    for f in COUNTED if f in c and event in c[f]]
        cticks = [t for t in xticks if str(t) in c[COUNTED[0]]["cycles"]]
        figure(outdir / f"{m}-counters.svg", [
            ("Cycles per operation", "cycles / op (per thread)", counter("cycles"), cticks, xmax, b, bl),
            (data["cross_label"], "per op", counter(data["cross_event"]), cticks, xmax, b, bl),
        ], f"Hardware counters per operation against pinned threads on {label}")


def main() -> None:
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd", required=True)
    a = sub.add_parser("aggregate")
    a.add_argument("--machine", required=True)
    a.add_argument("--label", required=True)
    a.add_argument("--boundary", type=float, required=True)
    a.add_argument("--boundary-label", required=True)
    a.add_argument("--cross-event", required=True)
    a.add_argument("--cross-label", required=True)
    a.add_argument("--runs", required=True)
    a.add_argument("--perf")
    a.set_defaults(func=aggregate)
    q = sub.add_parser("plot")
    q.add_argument("data")
    q.set_defaults(func=plot)
    args = p.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
