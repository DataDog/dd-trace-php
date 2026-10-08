# /// script
# requires-python = ">=3.11"
# dependencies = ["matplotlib==3.10.7"]
# ///
"""Performance figures of the README.

  uv run plot.py aggregate --machine 9950x --label "..." --boundary 8.5 \
      --boundary-label "CCD 1" --cross-event ls_any_fills_from_sys.near_cache \
      --runs 'raw/r*.json' --perf 'raw/perfstat/*.csv' > data/9950x.json
  uv run plot.py plot data/9950x.json   # writes 9950x-*-{light,dark}.svg
  uv run plot.py l3lat --ibs 'ibs/*-t*.txt' > data/9950x-l3lat.json
  uv run plot.py bars   # sharing, fill-source and per-CCD charts

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
    for path in glob.glob(args.perf or ""):
        m = re.match(r"(.+)-t(\d+)-reps(\d+)-\d+\.csv", os.path.basename(path))
        scen = {f.replace("/", "_"): f for f in runs}.get(m[1], m[1])
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
    """One panel: series = [(name, [(threads, median, low, high), ...])], or
    (name, points, style) where style may set "slot" (palette index) and
    "ls" (line style), so that one entity keeps its color across series."""
    ax.axvspan(boundary, xmax, color=theme["zone"], alpha=0.08, lw=0)
    if ref is not None:
        ax.axhline(ref, color=theme["muted"], lw=1, ls=(0, (4, 3)))
    ends = []
    for k, entry in enumerate(series):
        name, pts, style = (*entry, {})[:3]
        color = theme["series"][style.get("slot", k)]
        x = [p[0] for p in pts]
        ax.fill_between(x, [p[2] for p in pts], [p[3] for p in pts], color=color, alpha=0.2, lw=0)
        ax.plot(x, [p[1] for p in pts], color=color, lw=2, marker="o", ms=3.5,
                ls=style.get("ls", "-"))
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
    if ref is None:
        # The boundary label sits at the top, just right of the boundary;
        # make room for it if a line passes there.
        near = [p[1] for _, pts, *_ in series for p in pts if boundary < p[0] < boundary + 2]
        if near and max(near) > top * 0.85:
            top = max(near) / 0.8
            ax.set_ylim(0, top)
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


def draw_bars(ax, theme: dict, title: str, xlabel: str, categories: list[str],
              series: list[tuple[str, list[tuple[float, float, float]]]]) -> None:
    """Horizontal bars: one group per category, one bar per series, each
    value given as (value, low, high); low < high draws a range."""
    n = len(series)
    height = 0.8 / n
    for k, (name, values) in enumerate(series):
        color = theme["series"][k]
        ys = [i + (k - (n - 1) / 2) * height for i in range(len(categories))]
        vals = [v[0] for v in values]
        ax.barh(ys, vals, height=height * 0.9, color=color, label=name)
        err = [[v[0] - v[1] for v in values], [v[2] - v[0] for v in values]]
        if any(e > 0 for side in err for e in side):
            ax.errorbar(vals, ys, xerr=err, fmt="none", ecolor=theme["text"], elinewidth=1,
                        capsize=3)
        for y, v in zip(ys, values):
            if v[0] > 0:
                ax.annotate(f"{v[0]:.0f}", xy=(v[2], y), xytext=(5, 0), textcoords="offset points",
                            va="center", color=theme["text"], fontsize=8.5)
    ax.set_yticks(range(len(categories)))
    ax.set_yticklabels(categories, color=theme["text"], fontsize=9)
    ax.invert_yaxis()
    ax.set_xlim(left=0, right=ax.get_xlim()[1] * 1.12)
    ax.set_title(title, loc="left", fontsize=11, fontweight="bold", color=theme["text"])
    ax.set_xlabel(xlabel, color=theme["muted"])
    ax.grid(axis="x", color=theme["grid"], lw=0.8)
    ax.set_axisbelow(True)
    for side in ("top", "right"):
        ax.spines[side].set_visible(False)
    for side in ("bottom", "left"):
        ax.spines[side].set_color(theme["muted"])
    ax.tick_params(colors=theme["muted"], length=3)
    if n > 1:
        legend = ax.legend(frameon=False, fontsize=8.5, loc="upper left",
                           bbox_to_anchor=(0, -0.24), ncol=min(n, 2))
        for text in legend.get_texts():
            text.set_color(theme["text"])


def figure(path: Path, panels: list[tuple], desc: str, kind: str = "lines",
           size: tuple[float, float] | None = None) -> None:
    import matplotlib
    matplotlib.use("svg")
    import matplotlib.pyplot as plt
    plt.rcParams.update({"svg.fonttype": "none", "font.family": "sans-serif",
                         "font.sans-serif": ["DejaVu Sans"], "svg.hashsalt": "sgc"})
    for name, theme in THEMES.items():
        fig, axes = plt.subplots(1, len(panels), figsize=size or (4.6 * len(panels), 3.1))
        axes = axes if len(panels) > 1 else [axes]
        for ax, args in zip(axes, panels):
            (draw if kind == "lines" else draw_bars)(ax, theme, *args)
        if kind == "lines":
            fig.subplots_adjust(left=0.07, right=0.84, wspace=0.62, top=0.88, bottom=0.16)
        else:
            fig.tight_layout()
        fig.savefig(path.with_name(f"{path.stem}-{name}.svg"), transparent=True,
                    bbox_inches="tight" if kind == "bars" else None,
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

    def throughput(fams: list[str]):
        return [(f, [(int(t), st.median(v), min(v), max(v)) for t, v in rows(f)]) for f in fams]

    def efficiency(fams: list[str]):
        out = []
        for f in fams:
            base = st.median(data["runs"][f]["1"])
            out.append((f, [(int(t), st.median(v) / int(t) / base, min(v) / int(t) / base,
                             max(v) / int(t) / base) for t, v in rows(f)]))
        return out

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
        second = (data["cross_label"], "per op", counter(data["cross_event"]), cticks, xmax, b, bl)
        # Where IBS latencies exist (the 9950X), they replace the count of
        # cross-boundary fills: how long a fill from any L3 takes matters more
        # than which L3 it came from.
        latency = Path(args.data).with_name(f"{m}-l3lat.json")
        if latency.exists():
            second = ("Latency of fills from an L3", "cycles (IBS mean)",
                      l3_latency_series(json.load(open(latency))), cticks, xmax, b, bl)
        figure(outdir / f"{m}-counters.svg", [
            ("Cycles per operation", "cycles / op (per thread)", counter("cycles"), cticks, xmax, b, bl),
            second,
        ], f"Hardware counters per operation against pinned threads on {label}")


# Series of the L3-latency panel: (label, scenario, source, palette slot,
# line style). Slots follow COUNTED, so each scenario keeps its color; the
# line style tells the source apart.
L3_SERIES = [
    ("insert_new: own L3", "insert_new/1Mi", "own_l3", 0, "-"),
    ("insert_new: other CCD", "insert_new/1Mi", "other_ccd", 0, "--"),
    ("lookup_hit: own L3", "lookup_hit/32Ki/s0.8", "own_l3", 2, "-"),
]
MIN_SAMPLES = 10  # fewer IBS samples than this give no meaningful mean


def l3_latency_series(lat: dict) -> list:
    out = []
    for label, scen, src, slot, ls in L3_SERIES:
        pts = [(int(t), v[src][1], v[src][1], v[src][1])
               for t, v in sorted(lat[scen].items(), key=lambda kv: int(kv[0]))
               if src in v and v[src][0] >= MIN_SAMPLES]
        if pts:
            out.append((label, pts, {"slot": slot, "ls": ls}))
    return out


def l3lat(args: argparse.Namespace) -> None:
    """Collects the IBS summaries (NAME-tT.txt: "source count mean" lines)
    into {scenario: {threads: {source: [count, mean]}}}."""
    names = {f.replace("/", "_"): f for f in READS + WRITES}
    out: dict = {}
    for path in sorted(glob.glob(args.ibs)):
        family, threads = os.path.basename(path)[:-4].rsplit("-t", 1)
        rows = {}
        for line in open(path):
            src, n, mean = line.split()
            rows[src] = [int(n), float(mean)]
        out.setdefault(names.get(family, family), {})[threads] = rows
    json.dump(out, sys.stdout, indent=1)
    sys.stdout.write("\n")


def bars(args: argparse.Namespace) -> None:
    """The sharing experiment and the fill sources of insert_new/1Mi."""
    outdir = Path(args.sharing).resolve().parent.parent
    sharing = json.load(open(args.sharing))
    cats = [p["label"] for p in sharing["placements"]]

    def ranged(key: str):
        return [(st.median(p[key]), min(p[key]), max(p[key])) for p in sharing["placements"]]

    figure(outdir / "9950x-sharing.svg", [
        ("Cycles per operation", "cycles / op", cats, [("cycles", ranged("cycles"))]),
        ("Latency of hits in the own L3", "cycles (IBS mean)", cats, [("latency", ranged("l3"))]),
    ], "insert_new/1Mi with 8 workers on the Ryzen 9 9950X in four placements",
        kind="bars", size=(10.5, 2.9))
    fills_data = json.load(open(args.fills))
    sources = [("own_l3", "own CCD's L3", "local_ccx"), ("other", "another CCD's cache", "near_cache"),
               ("own", "ownership of a shared line", "dram_upgrade"), ("dram", "DRAM", "dram_read")]
    cats = [label for _, label, _ in sources]
    series = []
    for cfg, label in (("insert_new_1Mi-t8", "8 threads (one CCD)"),
                       ("insert_new_1Mi-t16", "16 threads (both CCDs)")):
        rows = fills_data[cfg]["sources"]
        vals = [(rows[key]["cycles_per_op"] or 0.0,) * 3 for _, _, key in sources]
        series.append((f"{label}: {fills_data[cfg]['cycles_per_op']:.0f} cycles/op", vals))
    figure(outdir / "9950x-fills.svg", [
        ("Miss latency per insert, by source", "cycles of miss latency per op (misses overlap)",
         cats, series),
    ], "Miss latency per operation by source for insert_new/1Mi on the Ryzen 9 9950X",
        kind="bars", size=(7.5, 3.0))
    per_ccd = json.load(open(args.per_ccd))["scenarios"]
    cats = sorted(per_ccd, key=lambda s: (not s.startswith("insert"), s))
    series = [(label, [(st.median(per_ccd[s][key]), min(per_ccd[s][key]), max(per_ccd[s][key]))
                       for s in cats])
              for key, label in (("alone", "1 instance, 8 workers on one CCD"),
                                 ("shared", "1 instance, 16 workers"),
                                 ("split", "2 instances, 8 workers each on its CCD"))]
    figure(outdir / "9950x-per-ccd.svg", [
        ("Aggregate throughput", "Mops/s", cats, series),
    ], "One shared instance against one instance per CCD on the Ryzen 9 9950X",
        kind="bars", size=(7.5, 3.0))


# --- Fill sources ------------------------------------------------------------

# Where a load's data came from, in table order: (key, label). Counter
# events are AMD Zen 5 ls_dmnd_fills_from_sys.* (demand data-cache fills).
SOURCES = [
    ("L1", "L1 hit"),
    ("L2", "L2"),
    ("local_ccx", "L3 or another core of the same CCD"),
    ("near_cache", "another CCD's cache"),
    ("dram_upgrade", "ownership upgrade (reported as DRAM)"),
    ("dram_read", "DRAM read"),
]
FILL_EVENTS = {"L2": "local_l2", "local_ccx": "local_ccx", "near_cache": "near_cache"}


def fills(args: argparse.Namespace) -> None:
    """Per-operation loads by source and their estimated share of cycles.

    Counts come from counters over the timed region (7 minus 1 repetitions);
    the mean latency per source from IBS samples (DcMissLat, core cycles).
    IBS labels the data source of a compare-and-swap whose line a preceding
    plain load already fetched shared as DRAM, although only ownership is
    missing; such locked "DRAM" misses are split from real DRAM reads, and
    the DRAM fill count is divided between the two in the ratio of samples.
    """
    counts: dict[str, dict[str, dict[int, list[float]]]] = defaultdict(
        lambda: defaultdict(lambda: defaultdict(list)))
    for path in glob.glob(args.counters):
        m = re.match(r"(.+)-reps(\d+)-\d+\.csv", os.path.basename(path))
        for row in csv.reader(open(path)):
            if len(row) > 2 and row[0] and not row[0].startswith("#"):
                try:
                    value = float(row[0])
                except ValueError:
                    continue
                counts[m[1]][row[2].replace("ls_dmnd_fills_from_sys.", "")][int(m[2])].append(value)
    ibs: dict[str, dict[str, list[float]]] = defaultdict(lambda: defaultdict(lambda: [0.0, 0.0]))
    for path in glob.glob(args.ibs):
        cfg = re.match(r"(.+)-ibslat(-\d+)?\.txt", os.path.basename(path))[1]
        for line in open(path):
            key, n, mean = line.split()
            ibs[cfg][key][0] += int(n)
            ibs[cfg][key][1] += int(n) * float(mean)
    out = {}
    for cfg in sorted(counts):
        threads = int(cfg.rsplit("-t", 1)[1])
        ops = TIMED_REPS * OPS_PER_REP * threads

        def per_op(event: str) -> float:
            v = counts[cfg][event]
            return (st.median(v[7]) - st.median(v[1])) / ops

        cycles, loads = per_op("cycles"), per_op("ls_dispatch.ld_dispatch")
        n = {k: max(0.0, per_op(e)) for k, e in FILL_EVENTS.items()}
        dram = max(0.0, per_op("dram_io_near"))
        up, rd = ibs[cfg]["dram_upgrade"][0], ibs[cfg]["dram_read"][0]
        n["dram_upgrade"] = dram * up / (up + rd) if up + rd else 0.0
        n["dram_read"] = dram - n["dram_upgrade"]
        fills_all = sum(max(0.0, per_op(e)) for e in (
            "local_l2", "local_ccx", "near_cache", "far_cache", "dram_io_near", "dram_io_far",
            "alternate_memories"))
        n["L1"] = max(0.0, loads - fills_all)
        lat = {k: (s / c if c else None) for k, (c, s) in ibs[cfg].items()}
        rows = {}
        for key, _ in SOURCES:
            latency = lat.get(key)
            cyc = n[key] * latency if latency is not None and key != "L1" else None
            rows[key] = {"per_op": n[key], "latency": latency, "cycles_per_op": cyc,
                         "share": cyc / cycles if cyc is not None else None,
                         "samples": ibs[cfg][key][0]}
        out[cfg] = {"cycles_per_op": cycles, "loads_per_op": loads, "sources": rows}
    json.dump(out, sys.stdout, indent=1)
    sys.stdout.write("\n")


def fills_table(args: argparse.Namespace) -> None:
    """Prints the README table from the data `fills` wrote."""
    data = json.load(open(args.data))
    cfgs = [c for c in args.order if c in data]
    names = {f.replace("/", "_"): f for f in READS + WRITES}

    def header(cfg: str) -> str:
        family, threads = cfg.rsplit("-t", 1)
        return f"{names.get(family, family)}, {threads} threads"

    head = ["Source"] + [header(c) for c in cfgs]
    lines = ["| " + " | ".join(head) + " |", "|---" + "|---:" * len(cfgs) + "|"]
    for key, label in SOURCES:
        cells = []
        for c in cfgs:
            r = data[c]["sources"][key]
            if r["per_op"] < 0.005:
                cells.append("-")
            elif r["share"] is None:
                cells.append(f"{r['per_op']:.2f}")
            else:
                cells.append(f"{r['per_op']:.2f} · {r['latency']:.0f} · {100 * r['share']:.0f}%")
        lines.append(f"| {label} | " + " | ".join(cells) + " |")
    # Misses overlap, so the shares are upper bounds and can sum past 100%.
    lines.append("| sum of the shares above | " + " | ".join(
        f"{100 * sum(r['share'] or 0 for r in data[c]['sources'].values()):.0f}%" for c in cfgs) + " |")
    lines.append("| **cycles per operation** | " + " | ".join(
        f"**{data[c]['cycles_per_op']:.0f}**" for c in cfgs) + " |")
    print("\n".join(lines))


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
    f = sub.add_parser("fills", help="fill sources per operation (9950X counters + IBS)")
    f.add_argument("--counters", required=True, help="perf stat CSVs, NAME-tT-repsR-I.csv")
    f.add_argument("--ibs", required=True, help="IBS latency summaries, NAME-tT-ibslat[-I].txt")
    f.set_defaults(func=fills)
    t = sub.add_parser("fills-table", help="print the README table of `fills` data")
    t.add_argument("data")
    t.add_argument("--order", nargs="+", default=[
        "insert_new_1Mi-t8", "insert_new_1Mi-t16", "mixed_64Ki_s0.8-t16", "lookup_hit_32Ki_s0.8-t16"])
    t.set_defaults(func=fills_table)
    lat = sub.add_parser("l3lat", help="collect IBS L3-fill latencies per thread count")
    lat.add_argument("--ibs", required=True, help="summaries NAME-tT.txt")
    lat.set_defaults(func=l3lat)
    bar = sub.add_parser("bars", help="draw the sharing-experiment and fill-source charts")
    bar.add_argument("--sharing", default="data/9950x-sharing.json")
    bar.add_argument("--fills", default="data/9950x-fills.json")
    bar.add_argument("--per-ccd", default="data/9950x-per-ccd.json")
    bar.set_defaults(func=bars)
    args = p.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
