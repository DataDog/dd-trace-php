#!/usr/bin/env python3
"""Validate and aggregate measurements from the existing release job graph."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys

from ci import WORKLOAD, write_json

ROOT = Path(__file__).resolve().parents[2]


def expected_php_abis():
    source = (ROOT / ".gitlab/generate-common.php").read_text()
    pairs = re.findall(r'"(\d+\.\d+)"\s*=>\s*"(\d{8})"', source)
    if len(pairs) != 11 or len(set(pairs)) != 11:
        raise ValueError("Cannot establish the canonical PHP release ABI matrix")
    return dict(pairs)


def metadata(path):
    values = {}
    for line in path.read_text().splitlines():
        key, value = line.split("=", 1)
        values[key] = value
    return values


def aggregate(directory, arch):
    abis = expected_php_abis()
    records = []
    provenance = None
    jobs = {}
    job_measurements = []
    for meta_path in sorted(directory.glob("*/metadata.txt")):
        meta = metadata(meta_path)
        if meta.get("arch") != arch:
            continue
        common = {key: meta.get(key) for key in ("commit", "pipeline", "version_sha256", "bridge_sha256", "libdatadog", "libddwaf")}
        if not all(common.values()):
            raise ValueError("Incomplete legacy provenance: " + str(meta_path))
        if provenance is None:
            provenance = common
        elif provenance != common:
            raise ValueError("Source or prepared VERSION drift across legacy jobs")
        job_records = []
        whole_job = None
        for path in sorted(meta_path.parent.glob("*.json")):
            item = json.loads(path.read_text())
            if item["exit_code"]:
                raise ValueError("Failed legacy command: " + item["identity"])
            if item["elapsed_seconds"] <= 0 or item["cpu_seconds"] < 0:
                raise ValueError("Invalid legacy duration or CPU: " + item["identity"])
            if item["category"] == "job":
                if whole_job is not None or item["identity"] != "job-" + meta["job"]:
                    raise ValueError("Invalid complete legacy job measurement: " + meta["job"])
                whole_job = item
                continue
            item["job"] = meta["job"]
            item["image"] = meta["image"]
            item["php_version"] = meta["php_version"]
            item["php_abi"] = meta["php_abi"]
            item["sdk_version"] = meta["sdk_version"]
            item["triplet"] = meta["triplet"]
            item["arch"] = arch
            item["cache"] = dict(cargo_policy=meta.get("cargo_cache_policy"),
                                  cargo_lock_sha256=meta.get("cargo_lock_sha256"),
                                  cargo_home=meta.get("cargo_home"),
                                  make_jobs=meta.get("make_jobs"),
                                  cargo_build_jobs=meta.get("cargo_build_jobs"))
            job_records.append(item)
            records.append(item)
        if not job_records:
            raise ValueError("Legacy job has no measured commands: " + meta["job"])
        if whole_job is None:
            raise ValueError("Legacy job has no complete process-tree measurement: " + meta["job"])
        job_start = whole_job["started_at_epoch"]
        job_finish = job_start + whole_job["elapsed_seconds"]
        if any(item["started_at_epoch"] < job_start - 0.01 or
               item["started_at_epoch"] + item["elapsed_seconds"] > job_finish + 0.01
               for item in job_records):
            raise ValueError("Legacy command falls outside complete job measurement: " + meta["job"])
        if whole_job["cpu_seconds"] + 0.01 < sum(item["cpu_seconds"] for item in job_records):
            raise ValueError("Complete legacy job CPU omits measured commands: " + meta["job"])
        whole_job["job"] = meta["job"]
        job_measurements.append(whole_job)
        jobs[meta["job"]] = dict(
            start=job_start,
            finish=job_finish,
            cpu=whole_job["cpu_seconds"],
            requested_cores=float(meta["cpu_request"]),
        )
    if provenance is None:
        raise ValueError("No legacy measurements for " + arch)
    expected = {"tracer": 55, "sidecar": 2, "link": 55}
    for category, count in expected.items():
        found = [item for item in records if item["category"] == category]
        if len(found) != count or len({item["identity"] for item in found}) != count:
            raise ValueError("Expected %d unique %s commands for %s, got %d" % (count, category, arch, len(found)))
    if len(records) != 112 or len(jobs) != 26:
        raise ValueError("Unexpected legacy commands or job count for " + arch)
    machine = "x86_64" if arch == "amd64" else "aarch64"
    triplets = {"glibc": machine + "-unknown-linux-gnu",
                "musl": machine + "-alpine-linux-musl"}
    expected_ids = {"tracer": set(), "link": set(), "sidecar": set()}
    for libc, triplet in triplets.items():
        expected_ids["sidecar"].add(triplet + "-sidecar")
        profiles = ("nts", "debug", "zts") if libc == "glibc" else ("nts", "zts")
        for version, abi in abis.items():
            for profile in profiles:
                expected_ids["tracer"].add("%s-%s-%s" % (triplet, version, profile))
                suffix = "" if profile == "nts" else "-" + profile
                alpine = "-alpine" if libc == "musl" else ""
                expected_ids["link"].add("%s-ddtrace-%s%s%s" % (triplet, abi, alpine, suffix))
    for category, identities in expected_ids.items():
        observed = {item["identity"] for item in records if item["category"] == category}
        if observed != identities:
            raise ValueError("Legacy release command identities differ for %s/%s" % (arch, category))
    for item in records:
        if item["category"] != "tracer":
            continue
        version = item["php_version"]
        if version not in abis or item["php_abi"] != abis[version]:
            raise ValueError("Legacy PHP ABI drift: " + item["identity"])
        meta = metadata(directory / item["job"] / "metadata.txt")
        if meta.get("sdk_abi") != abis[version]:
            raise ValueError("Actual legacy PHP SDK ABI drift: " + item["identity"])
        if not item["sdk_version"].startswith(version + "."):
            raise ValueError("Legacy PHP SDK version drift: " + item["identity"])
    sdk_versions = {}
    for item in records:
        if item["category"] != "tracer":
            continue
        libc = "musl" if "alpine" in item["triplet"] else "glibc"
        key = libc + ":" + item["php_version"]
        previous = sdk_versions.setdefault(key, item["sdk_version"])
        if previous != item["sdk_version"]:
            raise ValueError("Legacy SDK versions differ across profiles: " + key)
    start = min(job["start"] for job in jobs.values())
    finish = max(job["finish"] for job in jobs.values())
    cpu = sum(job["cpu"] for job in jobs.values())
    requested = sum(job["requested_cores"] * (job["finish"] - job["start"]) for job in jobs.values())
    expected_extensions = set()
    product_ids = []
    for abi in abis.values():
        expected_extensions.update("ddtrace-%s%s.so" % (abi, suffix)
                                   for suffix in ("", "-debug", "-zts", "-alpine", "-alpine-zts"))
    for version in abis:
        product_ids.extend("glibc:%s:%s" % (version, profile) for profile in ("nts", "debug", "zts"))
        product_ids.extend("musl:%s:%s" % (version, profile) for profile in ("nts", "zts"))
    extensions = list((ROOT / ("extensions_" + machine)).glob("ddtrace-*.so"))
    if {path.name for path in extensions} != expected_extensions or len(extensions) != 55:
        raise ValueError("Missing or extra linked release extensions for " + arch)
    sidecars = [ROOT / ("libdatadog_php_" + machine + suffix + ".so")
                for suffix in ("", "-alpine")]
    outputs = []
    for path in extensions + sidecars:
        if not path.is_file() or path.stat().st_size <= 0:
            raise ValueError("Missing or empty legacy release output: " + str(path))
        sha = hashlib.sha256()
        with path.open("rb") as source:
            for chunk in iter(lambda: source.read(1024 * 1024), b""):
                sha.update(chunk)
        outputs.append(dict(path=str(path.relative_to(ROOT)), bytes=path.stat().st_size,
                            sha256=sha.hexdigest()))
    result = dict(mode="legacy", arch=arch, scope=WORKLOAD, exit_code=0,
                provenance=dict(commit=provenance["commit"], pipeline=provenance["pipeline"],
                                dirty=False, version_sha256=provenance["version_sha256"],
                                bridge_sha256=provenance["bridge_sha256"],
                                submodules={"libdatadog": provenance["libdatadog"],
                                            "appsec/third_party/libddwaf-rust": provenance["libddwaf"]}),
                started_at_epoch=start, finished_at_epoch=finish,
                elapsed_seconds=finish - start, runner_cpu_seconds=cpu,
                total_cpu_seconds=cpu, runner_requested_core_seconds=requested,
                extension_outputs=55, sidecar_outputs=2, command_count=len(records),
                jobs=len(jobs), commands=records, job_measurements=job_measurements,
                sdk_versions=sdk_versions,
                product_ids=sorted(product_ids))
    return result, outputs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--arch", choices=("amd64", "arm64"), required=True)
    args = parser.parse_args()
    output = ROOT / "artifacts/bazel" / ("legacy-" + args.arch)
    output.mkdir(parents=True, exist_ok=True)
    try:
        result, outputs = aggregate(ROOT / "artifacts/bazel/legacy", args.arch)
        write_json(output / "outputs.json", outputs)
    except (OSError, ValueError, KeyError) as error:
        result = dict(mode="legacy", arch=args.arch, scope=WORKLOAD,
                      exit_code=1, validation_error=str(error))
    write_json(output / "result.json", result)
    return result["exit_code"]


if __name__ == "__main__":
    sys.exit(main())
