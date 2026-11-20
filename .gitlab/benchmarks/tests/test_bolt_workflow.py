#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.10"
# dependencies = []
# ///
"""Exercise checkout/build/rewrite/run ordering without compiling LLVM or PHP."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


STEPS = Path(__file__).resolve().parents[1] / "steps"

RUNALL = r'''#!/usr/bin/env bash
set -euo pipefail
# DD_TRACE_ASSUME_COMPILED: combined-extension harness capability marker.
revision=$(cat ../revision)
echo "run:$revision:${DD_TRACE_ASSUME_COMPILED:-no}" >> "$TEST_TRACE"
if [ "${TEST_CORRUPT_MODULE:-}" = 1 ]; then
  echo wrong > "$TEST_EXTENSION_DIR/ddtrace.so"
fi
case "$SCENARIO" in
  tracer)
    printf 'a,b,subject\n1,2,bench\n' > "$ARTIFACTS_DIR/tracer-bench-results.csv"
    cp "$ARTIFACTS_DIR/tracer-bench-results.csv" "$ARTIFACTS_DIR/tracer-bench-results-opcache.csv"
    echo '{}' > "$ARTIFACTS_DIR/tracer-tea-bench-results.json" ;;
  appsec)
    printf 'a,b,subject\n1,2,bench\n' > "$ARTIFACTS_DIR/appsec-bench-results.csv" ;;
  profiler)
    echo '{}' > "$ARTIFACTS_DIR/sirun_timeline.ndjson"
    echo '{}' > "$ARTIFACTS_DIR/sirun_exceptions.ndjson" ;;
esac
'''

MOCK = r'''#!/usr/bin/env python3
import os
from pathlib import Path
import subprocess
import sys

command = Path(sys.argv[0]).name
args = sys.argv[1:]
trace = Path(os.environ["TEST_TRACE"])
def event(value):
    with trace.open("a") as output:
        output.write(value + "\n")

if command == "git":
    if args[0] == "clone":
        destination = Path(args[-1])
        (destination / "benchmark").mkdir(parents=True)
        harness = Path(os.environ["TEST_HARNESS"]).read_text()
        if os.environ.get("TEST_OLD_HARNESS") == "1":
            harness = harness.replace("DD_TRACE_ASSUME_COMPILED", "OLD_HARNESS")
        (destination / "benchmark/runall.sh").write_text(harness)
        (destination / "benchmark/runall.sh").chmod(0o755)
    elif args[0] == "-C":
        destination = Path(args[1])
        if args[2] == "checkout":
            (destination / "revision").write_text(args[3])
        elif args[2] == "merge-base":
            assert args[3:] == ["origin/base", "candidate-sha"], args
            print("baseline-sha")
    else:
        raise AssertionError(args)
elif command == "github-find-merge-into-branch":
    sys.exit(1) if os.environ.get("TEST_LOOKUP_FAILURE") else None
    print(os.environ.get("TEST_BASELINE", "base"))
elif command == "nproc":
    print(2)
elif command == "make":
    assert args == ["-j2", "compile_combined"], args
    assert "--emit-relocs" in os.environ["LDFLAGS"]
    assert "-fno-reorder-blocks-and-partition" in os.environ["CFLAGS"]
    revision = Path("revision").read_text()
    event("build:" + revision)
    module = Path("tmp/build_combined/modules/ddtrace.so")
    module.parent.mkdir(parents=True)
    module.write_text("original:" + revision)
elif command in ["llvm-bolt", "llvm-bolt-align"]:
    if args == ["--version"]:
        print("test LLVM version")
    elif command == "llvm-bolt-align":
        event("align")
        for flag, original in [("-o-a=", args[0]), ("-o-b=", args[1])]:
            target = Path(next(a[len(flag):] for a in args if a.startswith(flag)))
            target.write_text("aligned:" + Path(original).read_text())
        count = 1 if os.environ.get("TEST_BAD_ALIGNMENT") else 2
        for _ in range(2):
            print(f"satisfied {count} of 2 layout constraints")
    else:
        event("plain")
        target = Path(next(a[3:] for a in args if a.startswith("-o=")))
        target.write_text("plain:" + Path(args[0]).read_text())
elif command == "php":
    print(os.environ["TEST_EXTENSION_DIR"])
elif command == "taskset":
    assert args[:2] == ["-c", "34-47"]
    sys.exit(subprocess.call(args[2:]))
else:
    raise AssertionError(command)
'''


class BoltWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        bin_dir = self.root / "bin"
        bin_dir.mkdir()
        mock = bin_dir / "mock"
        mock.write_text(MOCK)
        mock.chmod(0o755)
        for name in ["git", "github-find-merge-into-branch", "nproc", "make",
                     "llvm-bolt", "llvm-bolt-align", "php", "taskset"]:
            (bin_dir / name).symlink_to(mock)
        self.artifacts = self.root / "reports"
        self.artifacts.mkdir()
        self.extension_dir = self.root / "extensions"
        self.extension_dir.mkdir()
        harness = self.root / "runall.sh"
        harness.write_text(RUNALL)
        self.trace = self.root / "trace"
        self.env = os.environ | {
            "PATH": f"{bin_dir}:{os.environ['PATH']}",
            "ARTIFACTS_DIR": str(self.artifacts),
            "CI_PROJECT_DIR": str(self.root),
            "CI_COMMIT_REF_NAME": "feature",
            "CI_COMMIT_SHA": "candidate-sha",
            "CI_PROJECT_NAME": "dd-trace-php",
            "TEST_TRACE": str(self.trace),
            "TEST_EXTENSION_DIR": str(self.extension_dir),
            "TEST_HARNESS": str(harness),
            "BP_SCENARIO": "tracer",
            "BOLT_MODE": "",
            "BASELINE_BRANCH": "",
            "BENCHMARK_SOURCE_ROOT": str(self.root / "app"),
        }

    def run_workflow(self, **env):
        result = subprocess.run(
            [str(STEPS / "run-benchmarks.sh")], env=self.env | env,
            capture_output=True, text=True,
        )
        return result, self.trace.read_text().splitlines() if self.trace.exists() else []

    def test_aligns_both_builds_before_any_timing_in_every_scenario(self):
        for scenario in ["tracer", "appsec", "profiler"]:
            with self.subTest(scenario=scenario):
                shutil.rmtree(self.root / "app", ignore_errors=True)
                self.trace.unlink(missing_ok=True)
                result, events = self.run_workflow(BP_SCENARIO=scenario)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(events, ["build:candidate-sha", "build:baseline-sha",
                                          "align", "run:candidate-sha:1", "run:baseline-sha:1"])
                self.assertEqual((self.artifacts / "bolt-mode.txt").read_text(), "align\n")
                self.assertTrue((self.artifacts / "bolt/alignment.log").is_file())
        self.assertIn("bench-opcache", (self.artifacts / "candidate-tracer-bench-results-opcache.csv").read_text())
        self.assertIn("bench-appsec", (self.artifacts / "baseline-appsec-bench-results.csv").read_text())
        self.assertTrue((self.root / "candidate.tar.gz").is_file())
        self.assertTrue((self.root / "baseline.tar.gz").is_file())
        self.assertFalse(list(self.artifacts.rglob("*.so")))
        self.assertFalse(list(self.artifacts.glob("tracer-*.csv")))
        self.assertFalse(list(self.artifacts.glob("appsec-*.csv")))

    def test_plain_rewrites_both_before_any_timing(self):
        result, events = self.run_workflow(BOLT_MODE="plain")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events, ["build:candidate-sha", "build:baseline-sha",
                                  "plain", "plain", "run:candidate-sha:1", "run:baseline-sha:1"])

    def test_checkouts_remain_available_to_shared_analyzer(self):
        result, _ = self.run_workflow()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((self.root / "app/candidate/revision").read_text(), "candidate-sha")
        self.assertEqual((self.root / "app/baseline/revision").read_text(), "baseline-sha")

    def test_off_does_not_prepare_or_inherit_assume_compiled(self):
        result, events = self.run_workflow(BOLT_MODE="off", DD_TRACE_ASSUME_COMPILED="1")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events, ["run:candidate-sha:no", "run:baseline-sha:no"])

    def test_candidate_only_defaults_to_plain(self):
        result, events = self.run_workflow(TEST_BASELINE="")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events, ["build:candidate-sha", "plain", "run:candidate-sha:1"])
        self.assertEqual((self.artifacts / "bolt-mode.txt").read_text(), "plain\n")

    def test_explicit_alignment_requires_a_baseline(self):
        result, events = self.run_workflow(BOLT_MODE="align", TEST_BASELINE="")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("requires a baseline revision", result.stderr)
        self.assertEqual(events, [])

    def test_invalid_mode_fails_before_checkout(self):
        result, events = self.run_workflow(BOLT_MODE="typo")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("must be off, plain, or align", result.stderr)
        self.assertEqual(events, [])

    def test_baseline_lookup_failure_is_not_a_candidate_only_run(self):
        result, events = self.run_workflow(TEST_LOOKUP_FAILURE="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(events, [])

    def test_unsatisfied_alignment_aborts_before_timing_and_retains_log(self):
        result, events = self.run_workflow(TEST_BAD_ALIGNMENT="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("did not satisfy", result.stderr)
        self.assertNotIn("run:candidate-sha:1", events)
        self.assertIn("satisfied 1 of 2", (self.artifacts / "bolt/alignment.log").read_text())

    def test_rebuilt_module_is_detected(self):
        result, events = self.run_workflow(TEST_CORRUPT_MODULE="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("run:baseline-sha:1", events)

    def test_old_separate_extension_harness_is_rejected(self):
        result, events = self.run_workflow(TEST_OLD_HARNESS="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("combined-extension harness", result.stderr)
        self.assertEqual(events, [])


if __name__ == "__main__":
    unittest.main()
