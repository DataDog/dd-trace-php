#!/usr/bin/env python3
"""Regression tests for savings accounting and CI isolation."""

import copy
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import urllib.request
from unittest.mock import patch

import ci
import ci_metrics as metrics
import ci_report as report
import legacy_aggregate
import network_probe
import repository_cache
import verify_release_outputs


def varint(value):
    result = bytearray()
    while value > 127:
        result.append((value & 127) | 128)
        value >>= 7
    result.append(value)
    return bytes(result)


def field(number, value):
    if isinstance(value, int):
        return varint(number << 3) + varint(value)
    if isinstance(value, str):
        value = value.encode()
    return varint((number << 3) | 2) + varint(len(value)) + value


def any_message(type_name, payload):
    return field(1, "type.googleapis.com/" + type_name) + field(2, payload)


def action_result(cpu=9):
    # Deliberately large historical usage catches accidental cached charging.
    usage = field(1, field(1, cpu)) + field(2, field(1, 1))
    auxiliary = any_message("buildbarn.resourceusage.POSIXResourceUsage", usage)
    return field(9, field(11, auxiliary))


def execution(name="operations/1", cached=False, usage=True, done=True, wait=False):
    response = field(1, action_result() if usage else b"") + field(4, int(cached))
    operation = field(1, name) + field(3, int(done))
    if done:
        operation += field(5, any_message("build.bazel.remote.execution.v2.ExecuteResponse", response))
    return field(4, field(9 if wait else 7, field(2, operation)))


def write_records(path, records):
    path.write_bytes(b"".join(varint(len(record)) + record for record in records))


class AccountingTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / "grpc.pb"

    def account(self, *records):
        write_records(self.path, records)
        return metrics.remote_cpu_metrics(self.path)

    def test_user_and_system_cpu_with_reconnect_deduplication(self):
        result = self.account(execution(), execution(wait=True))
        self.assertEqual(result["cpu_seconds"], 10)
        self.assertEqual(result["executed_operations"], 1)

    def test_distinct_retries_are_charged(self):
        result = self.account(execution(), execution(name="operations/retry"))
        self.assertEqual(result["cpu_seconds"], 20)

    def test_cached_execute_and_action_cache_do_not_charge_historical_cpu(self):
        get_action = field(4, field(8, field(2, action_result(5000))))
        result = self.account(execution(cached=True), get_action)
        self.assertEqual(result["cpu_seconds"], 0)
        self.assertTrue(result["complete"])

    def test_nonzero_execute_status_is_not_a_cache_hit(self):
        failed_response = field(1, action_result()) + field(2, field(1, 7))
        operation = field(1, "operations/status") + field(3, 1) + field(
            5, any_message("build.bazel.remote.execution.v2.ExecuteResponse", failed_response))
        result = self.account(field(4, field(7, field(2, operation))))
        self.assertEqual(result["cached_operations"], 0)
        self.assertEqual(result["executed_operations"], 1)

    def test_missing_usage_is_unknown_not_zero(self):
        result = self.account(execution(usage=False))
        self.assertIsNone(result["cpu_seconds"])
        self.assertEqual(result["missing_usage"], 1)

    def test_unfinished_rpc_does_not_claim_complete_cpu(self):
        self.assertIsNone(self.account(execution(done=False))["cpu_seconds"])

    def test_rpc_failure_does_not_claim_complete_cpu(self):
        self.assertIsNone(self.account(execution() + field(2, field(1, 14)))["cpu_seconds"])

    def test_truncated_logs_fail(self):
        self.path.write_bytes(b"\x20truncated")
        with self.assertRaisesRegex(ValueError, "Truncated"):
            metrics.remote_cpu_metrics(self.path)

    def test_compact_log_remote_cached_and_failed(self):
        # remotable=13 is true for successful spawns; it is NOT an exit code.
        spawn = field(11, "remote") + field(13, 1)
        cached = field(11, "remote cache hit") + field(12, 1)
        failed = field(11, "linux-sandbox") + field(9, 1)
        write_records(self.path, [field(7, spawn), field(7, cached), field(7, failed)])
        compressed = self.path.with_suffix(".zst")
        compressed.write_bytes(subprocess.check_output(["zstd", "-qc", str(self.path)]))
        result = metrics.execution_metrics(compressed)
        self.assertEqual(result["remote_executed"], 1)
        self.assertEqual(result["remote_cache_hits"], 1)
        self.assertEqual(result["failed_spawns"], 1)
        self.assertEqual(result["local_executed"], 1)
        with self.assertRaisesRegex(ValueError, "local spawns"):
            metrics.validate_remote(result, {})

    def test_missing_execute_records_invalidates_cpu_total(self):
        result = dict(spawns=1, local_executed=0, failed_spawns=0, remote_executed=1)
        cpu = self.account()
        metrics.validate_remote(result, cpu, require_execution=True)
        self.assertIsNone(cpu["cpu_seconds"])

    def test_forced_cache_only_and_retained_outputs_fail(self):
        cpu = self.account()
        result = dict(spawns=1, local_executed=0, failed_spawns=0, remote_executed=0)
        with self.assertRaisesRegex(ValueError, "did not execute"):
            metrics.validate_remote(result, cpu, require_execution=True)
        result["spawns"] = 0
        with self.assertRaisesRegex(ValueError, "No spawn evidence"):
            metrics.validate_remote(result, cpu)


class ReleaseSdkEvidenceTests(unittest.TestCase):
    def test_release_versions_come_from_imported_images_not_historical_source_pins(self):
        lock = json.loads((ci.ROOT / "bazel/dependencies/php_oci/images.json").read_text())
        for arch in ("amd64", "arm64"):
            rows = []
            for record in lock["imports"]:
                if record["platform"]["arch"] != arch or record["image_family"] not in ("centos7", "alpine322"):
                    continue
                rows.append(dict(target_arch=arch,
                                 sdk_family="release" if record["image_family"] == "centos7" else "alpine",
                                 shared_build=False, sanitizer="none",
                                 product_labels={"tracer": "//bazel/products/tracer:release"},
                                 php_minor=record["minor"], abi_profile=record["abi_profile"],
                                 target_libc=record["target_libc"],
                                 php_version=record["declared_source_version"]))
            versions, identities = ci.release_sdk_versions(rows, arch, lock)
            self.assertEqual(len(identities), 55)
            self.assertEqual(len(versions), 22)
            self.assertEqual(versions["glibc:8.5"], "8.5.10")
            self.assertEqual(versions["musl:8.2"], "8.2.33")
            self.assertTrue(any(row["php_version"] == "8.5.7" for row in rows))
            rows[0]["php_version"] = "wrong-source-pin"
            with self.assertRaisesRegex(ValueError, "lock differs"):
                ci.release_sdk_versions(rows, arch, lock)


class FocusedTestEvidenceTests(unittest.TestCase):
    def test_all_five_remote_test_summaries_are_required(self):
        with tempfile.TemporaryDirectory() as directory:
            events = Path(directory) / "events.jsonl"
            summaries = [dict(id={"testSummary": {"label": label}},
                              testSummary={"overallStatus": "PASSED"})
                         for label in sorted(ci.EXPECTED_PHP_TESTS)]

            def write(items):
                events.write_text("".join(json.dumps(item) + "\n" for item in items))

            write(summaries)
            self.assertEqual(ci.focused_test_results(events), sorted(ci.EXPECTED_PHP_TESTS))
            write(summaries[:-1])
            with self.assertRaisesRegex(ValueError, "incomplete"):
                ci.focused_test_results(events)
            failed = copy.deepcopy(summaries)
            failed[0]["testSummary"]["overallStatus"] = "FAILED"
            write(failed)
            with self.assertRaisesRegex(ValueError, "failed"):
                ci.focused_test_results(events)


def sample(mode, arch="amd64", seconds=10, cpu=20):
    return dict(mode=mode, arch=arch, scope=ci.WORKLOAD, exit_code=0,
                elapsed_seconds=seconds, runner_cpu_seconds=2,
                total_cpu_seconds=cpu, extension_outputs=55, sidecar_outputs=2,
                sdk_versions={"glibc:8.5": "8.5.7"},
                product_ids=["product-%02d" % n for n in range(55)],
                remote_cpu=dict(complete=True, cpu_seconds=cpu - 2),
                standalone_abi=[dict(machine=62), dict(machine=62)],
                started_at="2026-09-23T00:00:00+00:00",
                provenance=dict(commit="same", pipeline="42", dirty=False,
                                version_sha256="version", bridge_sha256="bridge",
                                submodules={"libdatadog": "sha"}))


class ReportTests(unittest.TestCase):
    def setUp(self):
        self.results = []
        self.inventories = {}
        for arch in report.ARCHES:
            self.results.extend([sample("legacy", arch, 100, 1000),
                                 sample("forced", arch, 30, 400),
                                 sample("cached", arch, 10, 10)])
            self.inventories[("legacy", arch)] = [
                dict(path="extensions_%s/ddtrace-%d.so" % (arch, n), sha256="a", bytes=10)
                for n in range(55)] + [
                dict(path="libdatadog_php_%s-%d.so" % (arch, n), sha256="a", bytes=10)
                for n in range(2)]
            for mode in ("forced", "cached"):
                self.inventories[(mode, arch)] = [
                    dict(path="bazel-out/bin/ddtrace_fat_%s_%d/ddtrace.so" % (arch, n),
                         sha256="a", bytes=10) for n in range(55)] + [
                    dict(path="bazel-out/bin/%d/libdatadog_php.so" % n, sha256="a", bytes=10)
                    for n in range(2)]

    def test_wall_and_hardware_savings_are_separate(self):
        result = report.compare(self.results, self.inventories)
        self.assertEqual(result[0]["elapsed"]["percent"], 70)
        self.assertEqual(result[0]["cpu"]["seconds"], 600)
        self.assertEqual(result[1]["cpu"]["percent"], 99)

    def test_missing_worker_cpu_is_invalid_evidence(self):
        self.results[1]["total_cpu_seconds"] = None
        with self.assertRaisesRegex(ValueError, "remote-worker CPU"):
            report.compare(self.results, self.inventories)

    def test_regressions_stay_negative(self):
        self.assertEqual(report.savings(50, 75)["percent"], -50)

    def test_invalid_baselines_are_rejected(self):
        for mutation in (lambda r: r.pop(),
                         lambda r: r.append(copy.deepcopy(r[0])),
                         lambda r: r[1].update(exit_code=1),
                         lambda r: r[1].update(extension_outputs=54),
                         lambda r: r[1]["provenance"].update(commit="different"),
                         lambda r: r[1]["provenance"].update(pipeline="43"),
                         lambda r: r[1]["provenance"].update(dirty=True),
                         lambda r: r[1]["provenance"].update(version_sha256="different"),
                         lambda r: r[1].update(sdk_versions={"glibc:8.5": "8.5.8"})):
            results = copy.deepcopy(self.results)
            mutation(results)
            with self.assertRaises(ValueError):
                report.compare(results, self.inventories)

    def test_parallel_graph_wall_is_not_sum_of_job_durations(self):
        self.results[3]["started_at_epoch"] = 10
        self.results[3]["finished_at_epoch"] = 110
        self.results[0]["started_at_epoch"] = 0
        self.results[0]["finished_at_epoch"] = 100
        self.assertEqual(report.graph_elapsed([self.results[0], self.results[3]]), 110)

    def test_changed_cached_output_is_rejected(self):
        outputs = copy.deepcopy(self.inventories)
        self.assertEqual(len(report.compare(self.results, outputs)), 6)
        outputs[("cached", "arm64")][0]["sha256"] = "b"
        with self.assertRaisesRegex(ValueError, "digests differ"):
            report.compare(self.results, outputs)

    def test_report_artifacts_and_missing_lane_gate(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            all_results = list(self.results)
            for arch in report.ARCHES:
                all_results.append(dict(mode="probe", arch=arch, scope="remote-architecture", exit_code=0))
                all_results.append(dict(mode="remaining", arch=arch,
                                        scope="remaining-normal-tracer-matrix", exit_code=0,
                                        extension_outputs=46))
            all_results.append(dict(mode="tests", arch="amd64", scope="focused-php-tests", exit_code=0,
                                    passed_tests=sorted(ci.EXPECTED_PHP_TESTS)))
            for result in all_results:
                path = root / (result["mode"] + "-" + result["arch"])
                path.mkdir()
                (path / "result.json").write_text(json.dumps(result))
                key = result["mode"], result["arch"]
                if key in self.inventories:
                    (path / "outputs.json").write_text(json.dumps(self.inventories[key]))
            (root / "prepare-start.epoch").write_text("100\n")
            (root / "prepare-finish.epoch").write_text("120\n")
            with patch.object(sys, "argv", ["ci_report.py", str(root)]):
                self.assertEqual(report.main(), False)
                self.assertTrue((root / "report.html").exists())
                self.assertEqual(json.loads((root / "report.json").read_text())["preparation_seconds"], 20)
                tests = root / "tests-amd64/result.json"
                valid_tests = tests.read_text()
                tests.write_text(json.dumps(dict(mode="tests", arch="amd64", exit_code=0)))
                self.assertEqual(report.main(), True)
                self.assertIn("Incomplete focused PHP test evidence", (root / "report.md").read_text())
                tests.write_text(valid_tests)
                (root / "cached-arm64/result.json").unlink()
                self.assertEqual(report.main(), True)
                self.assertIn("Missing cached / arm64 result", (root / "report.md").read_text())


class RunnerTests(unittest.TestCase):
    def test_lane_runs_php_tests_early_without_dropping_release_coverage(self):
        expected = {
            "amd64": ["probe", "tests", "forced", "cached", "remaining"],
            "arm64": ["probe", "forced", "cached", "remaining"],
        }
        for arch, modes in expected.items():
            with self.subTest(arch=arch), tempfile.TemporaryDirectory() as directory, \
                    patch.object(ci, "ARTIFACTS", Path(directory)), patch("ci.os.chdir"), \
                    patch.object(sys, "argv", ["ci.py", "lane", "--arch", arch]), \
                    patch("ci.run_bazel", return_value=0) as run:
                self.assertEqual(ci.main(), 0)
                self.assertEqual([call.args[0] for call in run.call_args_list], modes)

    def test_remote_bep_reference_requires_download_and_matching_digest(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            relative = Path("bazel-out/test/bin/product.so")
            output = root / "execroot/_main" / relative
            output.parent.mkdir(parents=True)
            output.write_bytes(b"release product")
            digest = hashlib.sha256(output.read_bytes()).hexdigest()
            events = root / "events.jsonl"
            event = {"namedSetOfFiles": {"files": [{
                "name": "product.so", "pathPrefix": ["bazel-out", "test", "bin"],
                "uri": "bytestream://cache/instance/blobs/%s/%d" % (digest, output.stat().st_size),
                "digest": digest, "length": str(output.stat().st_size),
            }]}}
            events.write_text(json.dumps(event) + "\n")
            self.assertEqual(ci.output_inventory(events, root)[0]["sha256"], digest)
            output.write_bytes(b"corrupted")
            with self.assertRaisesRegex(ValueError, "length mismatch|digest or size mismatch"):
                ci.output_inventory(events, root)
            output.unlink()
            with self.assertRaisesRegex(ValueError, "not downloaded"):
                ci.output_inventory(events, root)

    def test_configs_are_isolated_and_cache_replay_uses_a_fresh_output_base(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {}, clear=True):
            forced = ci.bazel_command(Path(directory), "forced", "amd64", ci.comparison_targets())
            cached = ci.bazel_command(Path(directory), "cached", "amd64", ci.comparison_targets())
            for command in (forced, cached):
                self.assertIn("--batch", command)
                self.assertIn("--noworkspace_rc", command)
                self.assertIn("--nohome_rc", command)
                self.assertIn("--remote_local_fallback=false", command)
                self.assertIn("--remote_upload_local_results=false", command)
                self.assertIn("--disk_cache=", command)
                self.assertIn("--remote_executor=" + ci.REMOTE_ENDPOINT, command)
                rc = Path(next(arg.split("=", 1)[1] for arg in command if arg.startswith("--bazelrc=")))
                self.assertNotIn("try-import %workspace%/.bazelrc.local", rc.read_text())
            outputs = [next(arg for arg in command if arg.startswith("--output_base=")) for command in (forced, cached)]
            self.assertNotEqual(*outputs)
            self.assertIn("--remote_accept_cached=false", forced)
            self.assertIn("--remote_accept_cached=true", cached)
            probe = ci.bazel_command(Path(directory), "probe", "arm64", ["//bazel/stages:remote_arch_arm64"])
            self.assertIn("--config=remote-arch-arm64", probe)
            tests = ci.bazel_command(Path(directory), "tests", "amd64", ["//bazel/tests:php_tests"], "test")
            self.assertIn("--test_output=errors", tests)
            self.assertIn("--@rules_python//python/config_settings:bootstrap_impl=script", tests)

    def test_empty_endpoint_override_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {"BAZEL_REMOTE_EXECUTOR": ""}):
            with self.assertRaisesRegex(ValueError, "must not disable"):
                ci.bazel_command(Path(directory), "matrix", "amd64", [])

    def test_unreviewed_endpoint_override_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {"BAZEL_REMOTE_EXECUTOR": "grpcs://other.example:443"}):
            with self.assertRaisesRegex(ValueError, "reviewed Buildbarn"):
                ci.bazel_command(Path(directory), "forced", "amd64", [])

    def test_failed_subprocess_still_publishes_wall_and_cpu_measurements(self):
        with tempfile.TemporaryDirectory() as directory, patch("ci.provenance", return_value={}):
            result = ci.measure([sys.executable, "-c", "sum(i*i for i in range(500000)); raise SystemExit(7)"],
                                Path(directory), "legacy", ci.WORKLOAD, "amd64")
            self.assertEqual(result["exit_code"], 7)
            self.assertGreater(result["runner_cpu_seconds"], 0)
            self.assertGreater(result["elapsed_seconds"], 0)
            self.assertTrue((Path(directory) / "result.json").exists())


class LegacyAggregateTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which("cc"), "C compiler unavailable")
    def test_outer_legacy_wrapper_preserves_failure_and_counts_children(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binary = root / "legacy-measure"
            record = root / "job.json"
            subprocess.check_call(["cc", "-std=gnu11", "-O2", "-o", str(binary),
                                   str(ci.ROOT / "tools/bazel/legacy-measure.c")])
            process = subprocess.run([
                str(binary), str(record), "job", "job-test", "bash", "-c",
                'python3 -c "sum(i*i for i in range(500000))" & wait $!; exit 7',
            ], check=False)
            self.assertEqual(process.returncode, 7)
            result = json.loads(record.read_text())
            self.assertEqual(result["exit_code"], 7)
            self.assertGreater(result["cpu_seconds"], 0)
            self.assertGreater(result["elapsed_seconds"], 0)

    def test_full_release_graph_and_missing_command(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            measure_root = root / "artifacts/bazel/legacy"
            # Use the actual supported minor sequence (7.0-7.4, 8.0-8.5).
            abis = {version: "%08d" % (20151012 + n)
                    for n, version in enumerate(["7.0", "7.1", "7.2", "7.3", "7.4",
                                                 "8.0", "8.1", "8.2", "8.3", "8.4", "8.5"])}

            def job(name, category, identities, version="", libc="glibc"):
                path = measure_root / name
                path.mkdir(parents=True)
                metadata = dict(commit="commit", pipeline="42", job=name, image="release-image",
                                arch="amd64", triplet="x86_64-alpine-linux-musl" if libc == "musl" else "x86_64-unknown-linux-gnu",
                                php_version=version, php_abi=abis.get(version, ""),
                                sdk_version=version + ".1" if version else "8.1.1",
                                sdk_abi=abis.get(version, ""), host_os="linux-gnu",
                                version_sha256="version", bridge_sha256="bridge",
                                libdatadog="submodule", libddwaf="submodule", cpu_request="12")
                (path / "metadata.txt").write_text("".join("%s=%s\n" % pair for pair in metadata.items()))
                for index, identity in enumerate(identities):
                    (path / ("%d.json" % index)).write_text(json.dumps(dict(
                        category=category, identity=identity, command=["true"],
                        started_at_epoch=100 + index, elapsed_seconds=2,
                        cpu_seconds=1, exit_code=0)))
                # The outer wrapper includes make clean, SDK selection, copies,
                # debug compression, and every nested measured command.
                (path / ("job-%s.json" % name)).write_text(json.dumps(dict(
                    category="job", identity="job-" + name, command=["release-script"],
                    started_at_epoch=99, elapsed_seconds=len(identities) + 3,
                    cpu_seconds=len(identities) + 5, exit_code=0)))

            for version in abis:
                for libc, profiles in (("glibc", ("nts", "debug", "zts")),
                                       ("musl", ("nts", "zts"))):
                    triplet = "x86_64-alpine-linux-musl" if libc == "musl" else "x86_64-unknown-linux-gnu"
                    job("compile-%s-%s" % (libc, version), "tracer",
                        ["%s-%s-%s" % (triplet, version, profile) for profile in profiles],
                        version, libc)
            for libc in ("glibc", "musl"):
                triplet = "x86_64-alpine-linux-musl" if libc == "musl" else "x86_64-unknown-linux-gnu"
                job("sidecar-" + libc, "sidecar", [triplet + "-sidecar"], libc=libc)
                profiles = ("nts", "debug", "zts") if libc == "glibc" else ("nts", "zts")
                job("link-" + libc, "link", [
                    "%s-ddtrace-%s%s%s" % (triplet, abis[version],
                                            "-alpine" if libc == "musl" else "",
                                            "" if profile == "nts" else "-" + profile)
                    for version in abis for profile in profiles], libc=libc)
            extension_root = root / "extensions_x86_64"
            extension_root.mkdir()
            for abi in abis.values():
                for suffix in ("", "-debug", "-zts", "-alpine", "-alpine-zts"):
                    (extension_root / ("ddtrace-%s%s.so" % (abi, suffix))).write_bytes(b"extension")
            for suffix in ("", "-alpine"):
                (root / ("libdatadog_php_x86_64%s.so" % suffix)).write_bytes(b"sidecar")
            with patch.object(legacy_aggregate, "ROOT", root), \
                 patch.object(legacy_aggregate, "expected_php_abis", return_value=abis):
                result, outputs = legacy_aggregate.aggregate(measure_root, "amd64")
                self.assertEqual(result["command_count"], 112)
                self.assertEqual(result["jobs"], 26)
                self.assertEqual(result["runner_cpu_seconds"], 112 + 26 * 5)
                self.assertEqual(len(outputs), 57)
                self.assertLess(result["elapsed_seconds"], sum(item["elapsed_seconds"] for item in result["commands"]))
                command = measure_root / "sidecar-musl/0.json"
                original = command.read_bytes()
                command.unlink()
                with self.assertRaisesRegex(ValueError, "no measured commands"):
                    legacy_aggregate.aggregate(measure_root, "amd64")
                command.write_bytes(original)
                (measure_root / "sidecar-musl/job-sidecar-musl.json").unlink()
                with self.assertRaisesRegex(ValueError, "no complete process-tree measurement"):
                    legacy_aggregate.aggregate(measure_root, "amd64")


class RepositoryCacheTests(unittest.TestCase):
    def test_corrupt_download_cannot_enter_shared_repository_cache(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            payload = b"locked download"
            digest = hashlib.sha256(payload).hexdigest()
            source = root / "staging/content_addressable/sha256" / digest / "file"
            source.parent.mkdir(parents=True)
            source.write_bytes(payload)
            destination = root / "outside"
            repository_cache.copy_verified(root / "staging", destination)
            self.assertEqual((destination / source.relative_to(root / "staging")).read_bytes(), payload)
            source.write_bytes(b"tampered")
            with self.assertRaisesRegex(ValueError, "Corrupt repository download"):
                repository_cache.copy_verified(root / "staging", destination)


class RedirectAuthTests(unittest.TestCase):
    def test_bearer_header_is_scoped_to_registry_host(self):
        handler = network_probe.HostScopedAuthorization()
        original = urllib.request.Request(
            "https://registry-1.docker.io/v2/example/blobs/sha256:deadbeef",
            headers={"Authorization": "Bearer secret", "Range": "bytes=0-0"},
        )
        same_host = handler.redirect_request(
            original, None, 307, "Temporary Redirect", {},
            "https://registry-1.docker.io/another/path",
        )
        self.assertTrue(same_host.has_header("Authorization"))
        signed_s3 = handler.redirect_request(
            original, None, 307, "Temporary Redirect", {},
            "https://docker-images-prod.s3.dualstack.us-east-1.amazonaws.com/signed",
        )
        self.assertFalse(signed_s3.has_header("Authorization"))
        self.assertTrue(signed_s3.has_header("Range"))


class StandaloneAbiTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which("cc"), "C compiler unavailable")
    def test_standalone_exports_debug_and_architecture(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "exports.c"
            output = root / "libdatadog_php.so"
            source.write_text("void ddog_sidecar_ping(void) {}\nvoid ddog_daemon_entry_point(void) {}\n")
            subprocess.check_call(["cc", "-g", "-fPIC", "-shared", str(source), "-o", str(output)])
            arch = "amd64" if os.uname().machine == "x86_64" else "arm64"
            self.assertTrue(verify_release_outputs.verify_standalone(output, arch)["debug"])
            content = bytearray(output.read_bytes())
            content[18:20] = (183 if arch == "amd64" else 62).to_bytes(2, "little")
            output.write_bytes(content)
            with self.assertRaisesRegex(ValueError, "Wrong standalone shared-library ELF ABI"):
                verify_release_outputs.verify_standalone(output, arch)


if __name__ == "__main__":
    unittest.main()
