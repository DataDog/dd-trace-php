#!/usr/bin/env python3
"""Run the required Bazel release lane and retain auditable remote accounting.

All subprocesses are waited for; batch mode lets getrusage include Bazel's
JVM and local children. Legacy jobs use a small C timing wrapper instead.
"""

import argparse
from datetime import datetime, timezone
import gzip
import hashlib
import json
import os
from pathlib import Path
import resource
import shutil
import subprocess
import struct
import sys
import tempfile
import time
from urllib.parse import unquote, urlparse
import uuid

ROOT = Path(__file__).resolve().parents[2]
ARTIFACTS = ROOT / "artifacts/bazel"
WORKLOAD = "full-linux-tracer-release-v1"
EXPECTED_PHP_TESTS = frozenset(
    "//bazel/tests:" + name for name in (
        "http_extension_test",
        "phpt_extension_disabled",
        "phpt_read_c_configuration",
        "phpt_dd_trace_multiple_write",
        "phpt_do_not_check_if_class_or_function_exists_by_default",
    )
)
REMOTE_ENDPOINT = "grpcs://buildbarn-frontend.us1.ddbuild.staging.dog:443"
REMOTE_INSTANCE = "ci/shared"
_state_directory = None


def state_directory():
    global _state_directory
    if _state_directory is None:
        return new_state_directory()
    return _state_directory


def new_state_directory():
    global _state_directory
    # Bazel rejects repository-contents caches inside the source workspace.
    _state_directory = Path(tempfile.mkdtemp(prefix="dd-php-bazel-ci-"))
    return _state_directory


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def file_hash(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def release_sdk_versions(rows, arch, lock):
    """Report the installed, digest-locked PHP SDKs used by release products.

    The product manifest's php_version is a historical source pin. Several
    release images now contain a newer patch version, so it cannot be used as
    evidence of the SDK that Bazel actually imported.
    """
    imports = {}
    for record in lock["imports"]:
        if record["image_family"] not in ("centos7", "alpine322"):
            continue
        key = (record["image_family"], record["minor"],
               record["platform"]["arch"], record["abi_profile"])
        if key in imports:
            raise ValueError("Duplicate locked release PHP SDK: %s" % (key,))
        imports[key] = record
    versions = {}
    product_ids = []
    for row in rows:
        if row["target_arch"] != arch or row["sdk_family"] not in ("release", "alpine") or row["shared_build"] or row["sanitizer"] != "none":
            continue
        if not row["product_labels"]["tracer"]:
            continue
        family = "centos7" if row["sdk_family"] == "release" else "alpine322"
        key = (family, row["php_minor"], arch, row["abi_profile"])
        record = imports.get(key)
        if record is None:
            raise ValueError("Missing locked release PHP SDK: %s" % (key,))
        if record["target_libc"] != row["target_libc"] or record["declared_source_version"] != row["php_version"]:
            raise ValueError("Release PHP SDK lock differs from product manifest: %s" % (key,))
        version_key = row["target_libc"] + ":" + row["php_minor"]
        version = record["observed_image_version"]
        previous = versions.setdefault(version_key, version)
        if previous != version:
            raise ValueError("Bazel SDK versions differ across profiles: " + version_key)
        product_ids.append("%s:%s:%s" % (row["target_libc"], row["php_minor"], row["abi_profile"]))
    if len(versions) != 22 or len(product_ids) != 55 or len(set(product_ids)) != 55:
        raise ValueError("Canonical release PHP SDK inventory is incomplete")
    return versions, sorted(product_ids)


def provenance():
    changed = subprocess.check_output(["git", "diff", "--name-only", "HEAD", "--"]).decode().splitlines()
    prepared = {"VERSION"}
    unexpected = [name for name in changed if name not in prepared and not name.startswith("src/bridge/_generated")]
    generated = sorted(ROOT.glob("src/bridge/_generated*.php"))
    if not generated:
        raise ValueError("Missing prepared bridge artifacts")
    bridge_lines = "".join("%s  %s\n" % (file_hash(path), path.relative_to(ROOT)) for path in generated)
    return dict(
        commit=subprocess.check_output(["git", "rev-parse", "HEAD"]).decode().strip(),
        dirty=bool(unexpected),
        unexpected_changes=unexpected,
        version_sha256=file_hash(ROOT / "VERSION"),
        bridge_sha256=hashlib.sha256(bridge_lines.encode()).hexdigest(),
        submodules={name: subprocess.check_output(["git", "-C", name, "rev-parse", "HEAD"]).decode().strip()
                    for name in ("libdatadog", "appsec/third_party/libddwaf-rust")},
        pipeline=os.environ.get("CI_PIPELINE_ID"),
        job_url=os.environ.get("CI_JOB_URL"),
        runner=os.environ.get("CI_RUNNER_DESCRIPTION"),
        image=os.environ.get("CI_JOB_IMAGE"),
    )


def cpu_time():
    # Include orchestration plus the whole waited-for process tree. No cgroup
    # root counters: those can include unrelated jobs on the runner.
    return sum(resource.getrusage(who).ru_utime + resource.getrusage(who).ru_stime
               for who in (resource.RUSAGE_SELF, resource.RUSAGE_CHILDREN))


def measure(command, record, mode, scope, arch):
    record.mkdir(parents=True, exist_ok=True)
    result = dict(mode=mode, scope=scope, arch=arch, provenance=provenance(),
                  started_at=datetime.now(timezone.utc).isoformat(), command=command)
    start_cpu, start = cpu_time(), time.monotonic()
    with (record / "build.log").open("wb") as log:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        for line in process.stdout:
            log.write(line)
            sys.stdout.buffer.write(line)
            sys.stdout.buffer.flush()
        process.stdout.close()
        result["exit_code"] = process.wait()
    result["elapsed_seconds"] = time.monotonic() - start
    result["runner_cpu_seconds"] = cpu_time() - start_cpu
    result["runner_requested_cores"] = float(os.environ.get("KUBERNETES_CPU_REQUEST", "0")) or None
    result["runner_requested_core_seconds"] = (
        result["runner_requested_cores"] * result["elapsed_seconds"]
        if result["runner_requested_cores"] else None)
    result["total_cpu_seconds"] = result["runner_cpu_seconds"] if mode == "legacy" else None
    write_json(record / "result.json", result)
    return result


def ci_rc(directory):
    # Avoid checkout-local processwrapper/offline/output-base overrides while
    # preserving the reviewed shared config. Never mutate the developer's rc.
    lines = (ROOT / ".bazelrc").read_text().splitlines()
    rc = directory / "ci.bazelrc"
    rc.write_text("\n".join(line for line in lines if line.strip() !=
                           "try-import %workspace%/.bazelrc.local") + "\n")
    return rc


def bazel_command(record, mode, arch, targets, verb="build"):
    directory = state_directory() / (mode + "-" + arch + "-" + uuid.uuid4().hex)
    directory.mkdir(parents=True)
    command = [os.environ.get("BAZEL_BINARY", str(ROOT / "build/bin/bazel")),
               "--batch", "--nosystem_rc", "--nohome_rc", "--noworkspace_rc",
               "--bazelrc=" + str(ci_rc(directory)),
               "--output_user_root=" + str(state_directory() / "user"),
               "--output_base=" + str(directory / "output"),
               "--host_jvm_args=-Xmx6g"]
    auth = os.environ.get("BAZEL_CI_AUTH_RC")
    if auth:
        command.append("--bazelrc=" + str(Path(auth).resolve()))
    remote_config = "remote-arch-" if mode == "probe" else "remote-hermetic-"
    command += [verb, "--config=" + remote_config + arch, "--config=ci",
                "--announce_rc=false", "--remote_local_fallback=false",
                "--remote_upload_local_results=false", "--disk_cache=",
                "--jobs=" + str(int(os.environ.get("BAZEL_REMOTE_JOBS", "25"))),
                "--repository_cache=" + os.environ.get("BAZEL_REPOSITORY_CACHE", str(state_directory() / "repository")),
                "--repository_contents_cache=" + os.environ.get("BAZEL_REPOSITORY_CONTENTS_CACHE", str(state_directory() / "repository-contents")),
                "--symlink_prefix=/", "--bes_backend=", "--bes_results_url=",
                "--profile=" + str(record / "profile.json.gz"),
                "--execution_log_compact_file=" + str(record / "execution.zst"),
                "--remote_grpc_log=" + str(record / "remote-grpc.pb"),
                "--build_event_json_file=" + str(record / "events.jsonl"),
                "--remote_accept_cached=" + ("false" if mode in ("forced", "probe") else "true"),
                "--remote_download_outputs=toplevel"]
    if os.environ.get("BAZEL_CI_OFFLINE_DEPS") == "1":
        command.append("--repository_disable_download")
    if mode in ("forced", "cached"):
        command.append("--config=rbe-benchmark")
        # The benchmark config requests minimal downloads for interactive use;
        # the comparison verifies every top-level product against its BEP digest.
        command.append("--remote_download_outputs=toplevel")
    if verb == "test":
        # Remote testlogs live in a temporary output base. Include failures in
        # the retained job trace so a broken required lane is diagnosable.
        command.append("--test_output=errors")
        # The default rules_python launcher requires /usr/bin/env python3 on
        # the worker. Its script bootstrap starts the pinned Python toolchain
        # directly, which is present in the test runfiles.
        command.append("--@rules_python//python/config_settings:bootstrap_impl=script")
    for variable, expected in (("BAZEL_REMOTE_EXECUTOR", REMOTE_ENDPOINT),
                               ("BAZEL_REMOTE_CACHE", REMOTE_ENDPOINT),
                               ("BAZEL_REMOTE_INSTANCE", REMOTE_INSTANCE)):
        value = os.environ.get(variable)
        if value is not None:
            if not value:
                raise ValueError(variable + " must not disable remote execution/caching")
            if value != expected:
                raise ValueError(variable + " differs from the reviewed Buildbarn endpoint")
    command.extend(["--remote_executor=" + REMOTE_ENDPOINT,
                    "--remote_cache=" + REMOTE_ENDPOINT,
                    "--remote_instance_name=" + REMOTE_INSTANCE])
    return command + targets


def output_inventory(events, output_base):
    files = {}
    # Bazel 9 uses the canonical main-repository name in its execution root,
    # even though MODULE.bazel declares the public module name dd_trace_php.
    execroot = output_base / "execroot/_main"
    with events.open() as stream:
        for line in stream:
            event = json.loads(line)
            for item in event.get("namedSetOfFiles", {}).get("files", []):
                uri = urlparse(item.get("uri", ""))
                relative = Path(*item.get("pathPrefix", [])) / item.get("name", "")
                if relative.is_absolute() or ".." in relative.parts or not item.get("name"):
                    raise ValueError("Invalid BEP output path: " + str(relative))
                path = Path(unquote(uri.path)) if uri.scheme == "file" else execroot / relative
                if uri.scheme not in ("file", "bytestream", "grpcs", "grpc"):
                    raise ValueError("Unsupported BEP output URI: " + item.get("uri", ""))
                if not path.is_file():
                    raise ValueError("Required BEP output was not downloaded: " + str(relative))
                digest = file_hash(path)
                size = path.stat().st_size
                if item.get("length") is not None and int(item["length"]) != size:
                    raise ValueError("BEP output length mismatch: " + str(relative))
                if uri.scheme != "file":
                    parts = uri.path.strip("/").split("/")
                    if "blobs" in parts:
                        index = parts.index("blobs") + 1
                    elif "compressed-blobs" in parts:
                        index = parts.index("compressed-blobs") + 2
                    else:
                        raise ValueError("Remote BEP output lacks digest: " + item.get("uri", ""))
                    if parts[index:index + 2] != [digest, str(size)]:
                        raise ValueError("Remote BEP output digest or size mismatch: " + str(relative))
                if item.get("digest") and item["digest"] != digest:
                    raise ValueError("BEP output digest mismatch: " + str(relative))
                previous = files.get(str(relative))
                if previous and (previous["bytes"], previous["sha256"]) != (size, digest):
                    raise ValueError("Conflicting BEP output references: " + str(relative))
                files[str(relative)] = dict(path=str(relative), bytes=size, sha256=digest)
    if not files:
        raise ValueError("No BEP output files")
    return [files[key] for key in sorted(files)]


def focused_test_results(events):
    """Require all five remote PHP tests to report a passing BEP summary."""
    summaries = {}
    with events.open() as stream:
        for line in stream:
            event = json.loads(line)
            if "testSummary" not in event:
                continue
            label = event["id"]["testSummary"]["label"]
            if label in summaries:
                raise ValueError("Duplicate PHP test summary: " + label)
            summaries[label] = event["testSummary"]["overallStatus"]
    if set(summaries) != EXPECTED_PHP_TESTS:
        raise ValueError("Focused PHP test summaries are incomplete or unexpected: " + str(sorted(summaries)))
    failures = [label for label, status in summaries.items() if status != "PASSED"]
    if failures:
        raise ValueError("Focused PHP tests failed: " + str(sorted(failures)))
    return sorted(summaries)


def run_bazel(mode, arch, targets, scope, verb="build"):
    from ci_metrics import execution_metrics, remote_cpu_metrics, validate_remote
    from verify_release_outputs import verify_standalone

    record = ARTIFACTS / (mode + "-" + arch)
    record.mkdir(parents=True, exist_ok=True)
    command = bazel_command(record, mode, arch, targets, verb)
    result = measure(command, record, mode, scope, arch)
    result["targets"] = targets
    try:
        result["execution"] = execution_metrics(record / "execution.zst")
        result["remote_cpu"] = remote_cpu_metrics(record / "remote-grpc.pb")
        validate_remote(result["execution"], result["remote_cpu"], mode in ("probe", "forced"))
        if not result["remote_cpu"]["complete"]:
            raise ValueError("Missing complete remote-worker CPU accounting")
        result["total_cpu_seconds"] = result["runner_cpu_seconds"] + result["remote_cpu"]["cpu_seconds"]
        if result["exit_code"] == 0 and mode == "tests":
            result["passed_tests"] = focused_test_results(record / "events.jsonl")
        if result["exit_code"] == 0 and mode != "tests":
            output_base = Path(next(arg.split("=", 1)[1] for arg in command if arg.startswith("--output_base=")))
            inventory = output_inventory(record / "events.jsonl", output_base)
            write_json(record / "outputs.json", inventory)
            result["output_files"] = len(inventory)
            if mode == "remaining":
                result["extension_outputs"] = sum("ddtrace_fat_" in item["path"] and item["path"].endswith("/ddtrace.so") for item in inventory)
                if result["extension_outputs"] != 46:
                    raise ValueError("Expected 46 remaining normal tracer products per architecture")
            if scope == WORKLOAD:
                result["extension_outputs"] = sum("ddtrace_fat_" in item["path"] and item["path"].endswith("/ddtrace.so") for item in inventory)
                result["sidecar_outputs"] = sum(item["path"].endswith("/libdatadog_php.so") for item in inventory)
                if result["extension_outputs"] != 55 or result["sidecar_outputs"] != 2:
                    raise ValueError("Expected 55 release extensions and two standalone Rust DSOs per architecture")
                result["standalone_abi"] = [verify_standalone(
                    output_base / "execroot/_main" / item["path"], arch)
                    for item in inventory if item["path"].endswith("/libdatadog_php.so")]
                manifest = [item for item in inventory if item["path"].endswith("/bazel/php/product_matrix.json")]
                if len(manifest) != 1:
                    raise ValueError("Missing canonical PHP product manifest")
                source = output_base / "execroot/_main" / manifest[0]["path"]
                rows = json.loads(source.read_text())["rows"]
                sdk_versions, product_ids = release_sdk_versions(
                    rows, arch, json.loads((ROOT / "bazel/dependencies/php_oci/images.json").read_text()))
                result["sdk_versions"] = sdk_versions
                result["product_ids"] = product_ids
    except (OSError, ValueError, KeyError, IndexError, struct.error) as error:
        result["validation_error"] = str(error)
        result["exit_code"] = result["exit_code"] or 1
    finally:
        # gRPC payloads contain the auditable resource metadata, but compress
        # well. Don't ship output bases, dependency trees or loose action logs.
        for name in ("remote-grpc.pb", "events.jsonl", "build.log"):
            path = record / name
            if path.exists():
                with path.open("rb") as source, gzip.open(str(path) + ".gz", "wb") as dest:
                    shutil.copyfileobj(source, dest)
                path.unlink()
        write_json(record / "result.json", result)
    return result["exit_code"]


def comparison_targets(arch="amd64"):
    return ["//bazel/products/tracer:ddtrace_fat_" + arch + "_release", "//bazel/php:product_matrix"] + [
        "//:rust_datadog_php_shared_%s_%s" % (arch, libc) for libc in ("glibc", "musl")]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["lane", "probe", "compare-rbe"])
    parser.add_argument("--arch", choices=["amd64", "arm64"], default="amd64")
    args = parser.parse_args()
    os.chdir(str(ROOT))
    ARTIFACTS.mkdir(parents=True, exist_ok=True)
    if args.mode in ("compare-rbe", "lane"):
        status = run_bazel("probe", args.arch, ["//bazel/stages:remote_arch_" + args.arch], "remote-architecture")
        if status:
            return status
        # Run the focused PHP tests before the long forced build so missing
        # test-only repository inputs fail early in the amd64 lane.
        if args.mode == "lane" and args.arch == "amd64":
            status = run_bazel("tests", args.arch, ["//bazel/tests:php_tests"], "focused-php-tests", "test")
            if status:
                return status
        status = run_bazel("forced", args.arch, comparison_targets(args.arch), WORKLOAD)
        if status:
            return status
        # Unique output base; neither local action cache nor outputs survive.
        status = run_bazel("cached", args.arch, comparison_targets(args.arch), WORKLOAD)
        if status or args.mode == "compare-rbe":
            return status
        status = run_bazel("remaining", args.arch,
                           ["//bazel/products/tracer:ddtrace_fat_" + args.arch + "_remaining"], "remaining-normal-tracer-matrix")
        return status
    return run_bazel("probe", args.arch, ["//bazel/stages:remote_arch_" + args.arch], "remote-architecture")


if __name__ == "__main__":
    sys.exit(main())
