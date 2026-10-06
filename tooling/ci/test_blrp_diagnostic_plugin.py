import contextlib
import io
import json
import os
import tempfile
import unittest
from unittest import mock

from tooling.ci.blrp_diagnostic_plugin import diagnostic_wait


class FakeClock:
    def __init__(self) -> None:
        self.now = 0.0

    def monotonic(self) -> float:
        return self.now

    def sleep(self, duration: float) -> None:
        self.now += duration


class FakeAgent:
    def __init__(self, shared_original_result: dict) -> None:
        self.shared_original_result = shared_original_result
        self.snapshots = [
            [
                {
                    "request_type": "app-started",
                    "runtime_id": "runtime-before",
                    "tracer_time": 10,
                    "application": {"service_name": "service-before"},
                    "payload": {
                        "configuration": [
                            {"name": "UNRELATED_CONFIGURATION", "value": "do-not-print-this"}
                        ]
                    },
                }
            ],
            [
                {
                    "request_type": "message-batch",
                    "runtime_id": "batch-runtime",
                    "tracer_time": 11,
                    "application": {"service_name": "batch-service"},
                    "payload": [
                        {
                            "request_type": "app-client-configuration-change",
                            "runtime_id": "runtime-after",
                            "tracer_time": 12,
                            "application": {"service_name": "service-after"},
                            "payload": {
                                "configuration": [
                                    {"name": "OTEL_BLRP_MAX_QUEUE_SIZE", "value": "64"}
                                ]
                            },
                        }
                    ],
                }
            ],
        ]
        self.index = 0

    def telemetry(self, *, clear: bool) -> list[dict]:
        self.assert_clear_is_false(clear)
        self.shared_original_result["OTEL_BLRP_MAX_QUEUE_SIZE"] = [
            {"name": "OTEL_BLRP_MAX_QUEUE_SIZE", "value": "64"}
        ]
        snapshot = self.snapshots[min(self.index, len(self.snapshots) - 1)]
        self.index += 1
        return snapshot

    @staticmethod
    def assert_clear_is_false(clear: bool) -> None:
        if clear:
            raise AssertionError("diagnostic polling must not clear telemetry")


class BlrpDiagnosticPluginTest(unittest.TestCase):
    def test_preserves_original_result_and_reports_late_configuration_without_values(self) -> None:
        clock = FakeClock()
        emitted: list[str] = []
        original_result = {
            "UNRELATED_CONFIGURATION": [{"name": "UNRELATED_CONFIGURATION", "value": "do-not-print-this"}]
        }
        agent = FakeAgent(original_result)

        def original_wait(_agent: FakeAgent) -> dict:
            return original_result

        result = diagnostic_wait(
            original_wait,
            agent,
            expected_name="OTEL_BLRP_MAX_QUEUE_SIZE",
            observation_seconds=5.0,
            poll_seconds=0.5,
            monotonic=clock.monotonic,
            sleep=clock.sleep,
            emit=emitted.append,
        )

        self.assertIsNot(result, original_result)
        self.assertEqual(
            result,
            {
                "UNRELATED_CONFIGURATION": [
                    {"name": "UNRELATED_CONFIGURATION", "value": "do-not-print-this"}
                ]
            },
        )
        self.assertNotIn("OTEL_BLRP_MAX_QUEUE_SIZE", result)
        records = [json.loads(line.removeprefix("BLRP_DIAGNOSTIC ")) for line in emitted]
        self.assertEqual(records[0]["phase"], "original-return")
        self.assertEqual(records[0]["returned_configuration_names"], ["UNRELATED_CONFIGURATION"])
        self.assertFalse(records[1]["target_present"])
        self.assertEqual(records[1]["events"][0]["runtime_id"], "runtime-before")
        self.assertTrue(any(record.get("target_present") for record in records[2:]))
        self.assertTrue(
            any(
                event.get("runtime_id") == "runtime-after"
                for record in records
                for event in record.get("events", [])
            )
        )
        self.assertEqual(clock.now, 5.0)
        self.assertEqual(records[-1]["elapsed_ms"], 5000)
        self.assertFalse(records[-1]["original_would_pass"])
        self.assertTrue(records[-1]["late_target_present"])
        serialized = "\n".join(emitted)
        self.assertNotIn("do-not-print-this", serialized)
        self.assertNotIn('"value"', serialized)

    def test_original_poll_events_are_observed_without_changing_arguments_or_events(self) -> None:
        from tooling.ci.blrp_diagnostic_plugin import _event_summaries

        class Agent:
            def telemetry(self, *, clear: bool) -> list[dict]:
                self.clear = clear
                return self.events

        agent = Agent()
        agent.events = [
            {"request_type": "app-started", "runtime_id": "original-runtime",
             "payload": {"configuration": [{"name": "UNRELATED", "value": "hidden"}]}},
            {"request_type": "app-started", "runtime_id": "sidecar-runtime",
             "application": {"language_version": "SIDECAR"},
             "payload": {"configuration": [{"name": "OTEL_BLRP_MAX_QUEUE_SIZE"}]}},
        ]
        emitted: list[str] = []
        original_events = agent.events
        clock = FakeClock()

        def original_wait(instance: Agent) -> dict:
            self.assertIs(instance.telemetry(clear=False), original_events)
            return {}

        result = diagnostic_wait(
            original_wait, agent, expected_name="OTEL_BLRP_MAX_QUEUE_SIZE",
            observation_seconds=0, monotonic=clock.monotonic,
            sleep=clock.sleep, emit=emitted.append,
        )
        records = [json.loads(line.removeprefix("BLRP_DIAGNOSTIC ")) for line in emitted]
        self.assertEqual(result, {})
        self.assertFalse(agent.clear)
        self.assertNotIn("telemetry", vars(agent))
        self.assertEqual(records[0]["phase"], "original-wait-snapshot")
        self.assertEqual(records[0]["events"][0]["runtime_id"], "original-runtime")
        self.assertEqual(len(_event_summaries(agent.events)), 1)
        self.assertFalse(records[-1]["late_target_present"])
        self.assertNotIn("hidden", "\n".join(emitted))

    def test_default_emitter_persists_worker_output(self) -> None:
        clock = FakeClock()
        original_result: dict = {}
        agent = FakeAgent(original_result)

        with tempfile.TemporaryDirectory() as directory:
            output_path = os.path.join(directory, "telemetry.jsonl")
            with mock.patch.dict(os.environ, {"BLRP_DIAGNOSTIC_OUTPUT": output_path}):
                with contextlib.redirect_stdout(io.StringIO()):
                    diagnostic_wait(
                        lambda _agent: original_result,
                        agent,
                        expected_name="OTEL_BLRP_MAX_QUEUE_SIZE",
                        observation_seconds=0,
                        monotonic=clock.monotonic,
                        sleep=clock.sleep,
                    )

            with open(output_path, encoding="utf-8") as output:
                records = [json.loads(line.removeprefix("BLRP_DIAGNOSTIC ")) for line in output]

        self.assertEqual(records[0]["phase"], "original-return")
        self.assertEqual(records[-1]["phase"], "observation-complete")


if __name__ == "__main__":
    unittest.main()
