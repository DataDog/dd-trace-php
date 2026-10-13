import collections
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest


ROOT = Path(__file__).resolve().parents[2]
SELECTOR = ROOT / ".gitlab/select-system-tests-shard.py"


def canonical_workflow(scenarios):
    midpoint = len(scenarios) // 2
    return {
        "endtoend_defs": {
            "parallel_jobs": [
                {"scenarios": scenarios[:midpoint]},
                {"scenarios": scenarios[midpoint:]},
            ]
        },
        "parametric": {"scenarios": []},
    }


def run_selector(workflow, shard_number, shard_count="4"):
    environment = os.environ.copy()
    environment.update(CI_NODE_INDEX=str(shard_number), CI_NODE_TOTAL=str(shard_count))
    return subprocess.run(
        [sys.executable, str(SELECTOR)],
        input=json.dumps(workflow),
        env=environment,
        capture_output=True,
        text=True,
    )


def select_scenarios(scenarios, shard_number, shard_count="4"):
    return run_selector(canonical_workflow(scenarios), shard_number, shard_count)


class PackageSystemTestsShardingTest(unittest.TestCase):
    def test_pinned_canonical_revision_runs_every_scenario_exactly_once(self):
        endtoend_scenarios = [f"SCENARIO_{index:03d}" for index in range(96)] + [
            "000_REVISION_2",
            "000_REVISION_3_A",
            "000_REVISION_3_B",
            "000_REVISION_4_A",
            "000_REVISION_4_B",
            "000_REVISION_4_C",
        ]
        workflow = canonical_workflow(endtoend_scenarios)
        workflow["parametric"]["scenarios"] = ["PARAMETRIC"]
        scenarios = endtoend_scenarios + ["PARAMETRIC"]
        executions = collections.Counter()

        for shard_number in range(1, 5):
            result = run_selector(workflow, shard_number)
            self.assertEqual(result.returncode, 0, result.stderr)
            executions.update(result.stdout.split())

        self.assertEqual(executions, collections.Counter({scenario: 1 for scenario in scenarios}))

    def test_staggered_unpinned_revisions_are_not_exactly_once(self):
        common = [f"SCENARIO_{index:03d}" for index in range(96)]
        revisions = [
            common,
            common + ["000_REVISION_2"],
            common + ["000_REVISION_3_A", "000_REVISION_3_B"],
            common + ["000_REVISION_4_A", "000_REVISION_4_B", "000_REVISION_4_C"],
        ]
        visible = set().union(*map(set, revisions))
        executions = collections.Counter()

        for shard_number, scenarios in enumerate(revisions, start=1):
            result = run_selector(canonical_workflow(scenarios), shard_number)
            self.assertEqual(result.returncode, 0, result.stderr)
            executions.update(result.stdout.split())

        self.assertEqual(len(visible), 102)
        self.assertNotEqual(
            executions,
            collections.Counter({scenario: 1 for scenario in visible}),
        )
        self.assertTrue(visible - executions.keys())

    def test_invalid_selection_fails(self):
        result = select_scenarios(["ONLY_ONE"], 4)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Failed to select tracer-release scenarios:", result.stderr)

    def test_invalid_canonical_schema_fails(self):
        invalid_workflows = [
            {"endtoend": {"scenarios": ["LEGACY_ONLY"]}},
            {"endtoend_defs": {"parallel_jobs": []}},
            {"endtoend_defs": {"parallel_jobs": [{}]}},
            {"endtoend_defs": {"parallel_jobs": [{"scenarios": ["HAS WHITESPACE"]}]}},
        ]

        for workflow in invalid_workflows:
            with self.subTest(workflow=workflow):
                result = run_selector(workflow, 1)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Failed to select tracer-release scenarios:", result.stderr)


if __name__ == "__main__":
    unittest.main()
