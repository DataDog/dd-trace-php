import json
from pathlib import Path
import subprocess
import sys
import unittest


HELPER = Path(__file__).with_name("extract_tracer_release_scenarios.py")


class ExtractTracerReleaseScenariosTest(unittest.TestCase):
    def run_helper(self, fixture, *, optimize=False):
        command = [sys.executable]
        if optimize:
            command.append("-O")
        command.append(str(HELPER))
        payload = fixture if isinstance(fixture, str) else json.dumps(fixture)
        return subprocess.run(command, input=payload, text=True, capture_output=True)

    def test_extracts_sorted_unique_scenarios_from_canonical_jobs(self):
        fixture = {
            "endtoend_defs": {
                "parallel_jobs": [
                    {"scenarios": ["INTEGRATIONS", "DEFAULT"]},
                    {"scenarios": ["DEFAULT"]},
                ]
            }
        }

        result = self.run_helper(fixture)

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "DEFAULT INTEGRATIONS\n")

    def test_rejects_missing_fields(self):
        fixtures = [
            ({}, "endtoend_defs"),
            ({"endtoend_defs": {}}, "parallel_jobs"),
            ({"endtoend_defs": {"parallel_jobs": [{}]}}, "scenarios"),
        ]

        for fixture, expected_error in fixtures:
            with self.subTest(fixture=fixture):
                result = self.run_helper(fixture)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(expected_error, result.stderr)

    def test_rejects_wrong_types(self):
        fixtures = [
            ([], "workflow parameters"),
            ({"endtoend_defs": []}, "endtoend_defs"),
            ({"endtoend_defs": {"parallel_jobs": {}}}, "parallel_jobs"),
            ({"endtoend_defs": {"parallel_jobs": [None]}}, "parallel job"),
            ({"endtoend_defs": {"parallel_jobs": [{"scenarios": {}}]}}, "scenarios"),
            (
                {"endtoend_defs": {"parallel_jobs": [{"scenarios": [1]}]}},
                "scenario must be a string",
            ),
            (
                {"endtoend_defs": {"parallel_jobs": [{"scenarios": "DEFAULT"}]}},
                "scenarios",
            ),
        ]

        for fixture, expected_error in fixtures:
            with self.subTest(fixture=fixture):
                result = self.run_helper(fixture)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(expected_error, result.stderr)

    def test_rejects_empty_and_whitespace_scenarios(self):
        fixtures = [
            ({"endtoend_defs": {"parallel_jobs": []}}, "scenario selection"),
            (
                {"endtoend_defs": {"parallel_jobs": [{"scenarios": []}]}},
                "scenario selection",
            ),
            (
                {"endtoend_defs": {"parallel_jobs": [{"scenarios": [""]}]}},
                "scenario name",
            ),
            (
                {"endtoend_defs": {"parallel_jobs": [{"scenarios": [" "]}]}},
                "scenario name",
            ),
            (
                {"endtoend_defs": {"parallel_jobs": [{"scenarios": ["BAD NAME"]}]}},
                "scenario name",
            ),
        ]

        for fixture, expected_error in fixtures:
            with self.subTest(fixture=fixture):
                result = self.run_helper(fixture)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(expected_error, result.stderr)

    def test_rejects_malformed_json(self):
        result = self.run_helper("{")

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("invalid JSON", result.stderr)

    def test_validation_survives_optimized_python(self):
        fixture = {"endtoend_defs": {"parallel_jobs": [{"scenarios": [" "]}]}}

        result = self.run_helper(fixture, optimize=True)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("scenario name", result.stderr)


if __name__ == "__main__":
    unittest.main()
