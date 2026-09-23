#!/usr/bin/env python3
"""Publish full release-build measurements; fail on missing or invalid evidence."""

import argparse
from datetime import datetime
import html
import json
from pathlib import Path
import sys

from ci import WORKLOAD, write_json

ARCHES = ("amd64", "arm64")
MODES = ("legacy", "probe", "forced", "cached", "remaining")


def savings(baseline, candidate):
    if baseline is None or candidate is None or baseline <= 0:
        return None
    return dict(seconds=baseline - candidate,
                percent=100 * (baseline - candidate) / baseline)


def span(result):
    if "started_at_epoch" in result:
        return result["started_at_epoch"], result["finished_at_epoch"]
    start = datetime.fromisoformat(result["started_at"]).timestamp()
    return start, start + result["elapsed_seconds"]


def graph_elapsed(results):
    spans = [span(result) for result in results]
    return max(end for _, end in spans) - min(start for start, _ in spans)


def index_results(results):
    indexed = {}
    for result in results:
        key = result["mode"], result["arch"]
        if key in indexed:
            raise ValueError("Duplicate measurement: %s / %s" % key)
        indexed[key] = result
    return indexed


def inventory_map(inventory):
    indexed = {}
    for item in inventory:
        key = item["path"]
        if key in indexed:
            raise ValueError("Duplicate output: " + key)
        if not item["sha256"] or item["bytes"] < 0 or (key.endswith(".so") and item["bytes"] == 0):
            raise ValueError("Invalid output digest or size: " + key)
        indexed[key] = (item["sha256"], item["bytes"])
    return indexed


def compare(results, inventories):
    indexed = index_results(results)
    comparisons = []
    for arch in ARCHES:
        required = [(mode, arch) for mode in ("legacy", "forced", "cached")]
        if any(key not in indexed for key in required):
            raise ValueError("Missing legacy, forced or cached release measurement for " + arch)
        baseline, forced, cached = [indexed[key] for key in required]
        for result in (baseline, forced, cached):
            provenance = result["provenance"]
            if result["scope"] != WORKLOAD or result["exit_code"] or result.get("validation_error"):
                raise ValueError("Failed or wrong-scope release measurement for " + arch)
            if provenance["dirty"] or any(provenance.get(key) != baseline["provenance"].get(key)
                                          for key in ("commit", "pipeline", "version_sha256", "bridge_sha256", "submodules")):
                raise ValueError("Source, submodule or prepared VERSION drift for " + arch)
            if result.get("extension_outputs") != 55 or result.get("sidecar_outputs") != 2:
                raise ValueError("Incomplete release product inventory for " + arch)
            if result.get("sdk_versions") != baseline.get("sdk_versions"):
                raise ValueError("Legacy and Bazel PHP SDK versions differ for " + arch)
            if len(result.get("product_ids", [])) != 55 or result["product_ids"] != baseline.get("product_ids"):
                raise ValueError("Legacy and Bazel release product identities differ for " + arch)
        for result in (forced, cached):
            if not result.get("remote_cpu", {}).get("complete") or result.get("total_cpu_seconds") is None:
                raise ValueError("Incomplete remote-worker CPU accounting for " + arch)
            if len(result.get("standalone_abi", [])) != 2:
                raise ValueError("Missing standalone Rust ABI checks for " + arch)
        left = inventory_map(inventories.get(("forced", arch), []))
        right = inventory_map(inventories.get(("cached", arch), []))
        baseline_outputs = inventory_map(inventories.get(("legacy", arch), []))
        baseline_extensions = [path for path in baseline_outputs if path.startswith("extensions_") and path.endswith(".so")]
        baseline_sidecars = [path for path in baseline_outputs if path.startswith("libdatadog_php_") and path.endswith(".so")]
        if len(baseline_outputs) != 57 or len(baseline_extensions) != 55 or len(baseline_sidecars) != 2:
            raise ValueError("Missing legacy release output digests for " + arch)
        extension_paths = [path for path in left if "ddtrace_fat_" in path and path.endswith("/ddtrace.so")]
        sidecar_paths = [path for path in left if path.endswith("/libdatadog_php.so")]
        if len(extension_paths) != 55 or len(sidecar_paths) != 2:
            raise ValueError("Missing Bazel release output digests for " + arch)
        if not left or left != right:
            raise ValueError("Forced and cached output digests differ for " + arch)
        for mode, candidate in (("forced", forced), ("cached", cached)):
            comparisons.append(dict(
                arch=arch, mode=mode,
                elapsed=savings(baseline["elapsed_seconds"], candidate["elapsed_seconds"]),
                cpu=savings(baseline["total_cpu_seconds"], candidate["total_cpu_seconds"]),
            ))
    for mode in ("forced", "cached"):
        legacy = [indexed[("legacy", arch)] for arch in ARCHES]
        candidate = [indexed[(mode, arch)] for arch in ARCHES]
        comparisons.append(dict(
            arch="both", mode=mode,
            elapsed=savings(graph_elapsed(legacy), graph_elapsed(candidate)),
            cpu=savings(sum(r["total_cpu_seconds"] for r in legacy),
                        sum(r["total_cpu_seconds"] for r in candidate)),
        ))
    return comparisons


def number(value):
    return "unavailable" if value is None else "{:.2f}".format(value)


def render(results, comparisons, errors, preparation=None):
    headers = ("Run", "Elapsed s", "Runner CPU s", "Worker CPU s", "Build CPU s",
               "Cache hits", "Cache misses", "Hit rate %", "Queue s", "Requested core-s", "Status")
    rows = []
    for result in sorted(results, key=lambda r: (r.get("arch", ""), r.get("mode", ""))):
        execution = result.get("execution", {})
        remote = result.get("remote_cpu", {})
        rows.append((result.get("mode", "?") + " / " + result.get("arch", "?"),
                     number(result.get("elapsed_seconds")),
                     number(result.get("runner_cpu_seconds")),
                     "0.00" if result.get("mode") == "legacy" else number(remote.get("cpu_seconds")),
                     number(result.get("total_cpu_seconds")),
                     str(execution.get("remote_cache_hits", "—")),
                     str(execution.get("remote_executed", "—")),
                     number(100 * execution["remote_hit_rate"] if execution.get("remote_hit_rate") is not None else None),
                     number(execution.get("queue_seconds")),
                     number(result.get("runner_requested_core_seconds")),
                     "passed" if result.get("exit_code") == 0 else "FAILED"))
    lines = ["# Full Linux tracer release build", "",
             "| " + " | ".join(headers) + " |",
             "| " + " | ".join("---" for _ in headers) + " |"]
    lines.extend("| " + " | ".join(row) + " |" for row in rows)
    lines += ["", "## Potential savings", "",
              "| Architecture | Cache mode | Wall savings s | Wall savings % | Build CPU savings s | Build CPU savings % |",
              "| --- | --- | ---: | ---: | ---: | ---: |"]
    for item in comparisons:
        wall, cpu = item["elapsed"], item["cpu"]
        lines.append("| {} | {} | {} | {} | {} | {} |".format(
            item["arch"], item["mode"], number(wall["seconds"] if wall else None),
            number(wall["percent"] if wall else None), number(cpu["seconds"] if cpu else None),
            number(cpu["percent"] if cpu else None)))
    if not comparisons:
        lines.append("| unavailable | unavailable | unavailable | unavailable | unavailable | unavailable |")
    regressions = [item for item in comparisons if any(
        metric and metric["seconds"] < 0 for metric in (item["elapsed"], item["cpu"]))]
    if regressions:
        lines += ["", "## Performance regressions", ""]
        lines.extend("- **{} / {}**: wall {} s; build CPU {} s".format(
            item["arch"], item["mode"], number(item["elapsed"]["seconds"] if item["elapsed"] else None),
            number(item["cpu"]["seconds"] if item["cpu"] else None)) for item in regressions)
    shadow = [r for r in results if r.get("mode") != "legacy"]
    shadow_cpu = sum(r.get("total_cpu_seconds") or 0 for r in shadow)
    shadow_requested = sum(r.get("runner_requested_core_seconds") or 0 for r in shadow)
    lines += ["", "The baseline includes 55 linked extensions and two standalone Rust shared libraries per architecture. "
              "Wall time follows the parallel compile/sidecar/link job graph. The both-architecture row "
              "uses the earliest start and latest finish across the parallel jobs; it does not sum job durations.", "",
              "Shared prepared-source work: {} s. It is outside both comparison lanes. "
              "The temporary shadow lanes add {} measured build CPU seconds and {} requested runner core-seconds "
              "to current CI cost; savings become real only after equivalent legacy work is retired.".format(
                  number(preparation), number(shadow_cpu), number(shadow_requested)), "",
              "Runner CPU is measured user+system time for waited build processes. Worker CPU is Buildbarn "
              "POSIX user+system metadata from new remote executions, with reconnected responses deduplicated "
              "and completed retries charged. Historical CPU returned on cache hits is excluded. "
              "Queue time is elapsed waiting, not CPU. Requested core-seconds are runner requests multiplied "
              "by measured job intervals; build-process CPU is not fleet-wide usage or money.", "",
              "Forced execution disables remote cache reads. Cached execution uses a fresh Bazel output base "
              "after forced execution and downloads all comparison outputs. The same verified repository "
              "downloads are reused. Local fallback and uploads of local results are disabled. "
              "Windows, ASan, profiler, AppSec, installer packaging and whole-pipeline savings are outside "
              "this comparison."]
    if errors:
        lines += ["", "## Invalid or missing evidence", ""] + ["- " + error for error in errors]
    markdown = "\n".join(lines) + "\n"
    table = "<table><thead><tr>" + "".join("<th>" + html.escape(h) + "</th>" for h in headers) + "</tr></thead><tbody>"
    table += "".join("<tr>" + "".join("<td>" + html.escape(c) + "</td>" for c in row) + "</tr>" for row in rows)
    table += "</tbody></table>"
    chart = ""
    regression_html = ""
    if regressions:
        regression_html = '<aside><h2>Performance regressions</h2><ul>' + "".join(
            "<li>{} / {}</li>".format(html.escape(item["arch"]), html.escape(item["mode"]))
            for item in regressions) + "</ul></aside>"
    if comparisons:
        for metric, label in (("elapsed_seconds", "Elapsed seconds"), ("total_cpu_seconds", "Build CPU seconds")):
            samples = [r for r in results if r.get("scope") == WORKLOAD and r.get(metric) is not None]
            scale = max((r[metric] for r in samples), default=1) or 1
            chart += "<h2>" + label + "</h2>"
            for result in samples:
                chart += '<div class="bar-row"><span>{}</span><meter min="0" max="{}" value="{}"></meter><b>{}</b></div>'.format(
                    html.escape(result["mode"] + " / " + result["arch"]), scale, result[metric], number(result[metric]))
    page = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Tracer build measurements</title>'
    page += '<style>body{font:16px system-ui;margin:2rem;color:#17243b}table{border-collapse:collapse;font-size:14px}td,th{padding:.6rem;border:1px solid #ccd;text-align:right}th:first-child,td:first-child{text-align:left}pre{white-space:pre-wrap;max-width:1100px;font:15px/1.5 system-ui}.bar-row{display:flex;gap:1rem;align-items:center;margin:.5rem 0}.bar-row span{width:10rem}meter{width:30rem;height:2rem}aside{background:#fff1f0;border:2px solid #c22;padding:1rem;margin:1rem 0}</style>'
    page += "<h1>Full Linux tracer release build</h1>" + regression_html + table + chart + "<pre>" + html.escape(markdown) + "</pre></html>\n"
    return markdown, page


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path)
    args = parser.parse_args()
    results, inventories, errors = [], {}, []
    for path in sorted(args.directory.glob("*/result.json")):
        try:
            result = json.loads(path.read_text())
            results.append(result)
            output_path = path.parent / "outputs.json"
            if output_path.exists():
                inventories[(result["mode"], result["arch"])] = json.loads(output_path.read_text())
        except (OSError, ValueError, KeyError) as error:
            errors.append(str(path) + ": " + str(error))
    expected = {(mode, arch) for arch in ARCHES for mode in MODES} | {("tests", "amd64")}
    present = {(r.get("mode"), r.get("arch")) for r in results}
    errors.extend("Missing %s / %s result" % key for key in sorted(expected - present))
    errors.extend("Failed %s / %s: %s" % (r.get("mode"), r.get("arch"),
                  r.get("validation_error", "build failed")) for r in results if r.get("exit_code") != 0)
    errors.extend("Incomplete remaining normal matrix for %s" % arch for arch in ARCHES
                  if ("remaining", arch) in present and next(
                      r for r in results if (r.get("mode"), r.get("arch")) == ("remaining", arch)
                  ).get("extension_outputs") != 46)
    preparation = None
    try:
        preparation = float((args.directory / "prepare-finish.epoch").read_text()) - float(
            (args.directory / "prepare-start.epoch").read_text())
        if preparation < 0:
            raise ValueError("Negative preparation duration")
    except (OSError, ValueError) as error:
        errors.append("Missing or invalid shared preparation measurement: " + str(error))
    try:
        comparisons = compare(results, inventories)
    except (ValueError, KeyError) as error:
        errors.append(str(error))
        comparisons = []
    markdown, page = render(results, comparisons, errors, preparation)
    args.directory.mkdir(parents=True, exist_ok=True)
    (args.directory / "report.md").write_text(markdown)
    (args.directory / "report.html").write_text(page)
    write_json(args.directory / "report.json", dict(results=results, comparisons=comparisons,
                                                    errors=errors, preparation_seconds=preparation))
    return bool(errors)


if __name__ == "__main__":
    sys.exit(main())
