#!/usr/bin/env python3
"""Regression checks for interpreting Bazel's binary measurement records."""

import importlib.util
import io
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("metrics", Path(__file__).with_name("runtime-log-metrics.py"))
metrics = importlib.util.module_from_spec(spec)
spec.loader.exec_module(metrics)


class MetricsTest(unittest.TestCase):
    def test_duration_seconds_and_nanos(self):
        # google.protobuf.Duration {seconds: 2, nanos: 500000000}
        self.assertEqual(metrics.duration(bytes.fromhex("08021080cab5ee01")), 2.5)

    def test_unknown_fields_and_truncation(self):
        self.assertEqual(metrics.one(metrics.fields(bytes.fromhex("080112036162631809")), 3), 9)
        with self.assertRaises(ValueError):
            list(metrics.records(io.BytesIO(bytes.fromhex("050801"))))
        with self.assertRaises(ValueError):
            metrics.fields(bytes.fromhex("12036162"))

    def test_rpc_payloads_are_not_log_sizes(self):
        # LogEntry.details.write.bytes_sent=1000, then read.bytes_read=256.
        records = bytes.fromhex("072205320318e8070722052a03188002")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "grpc.pb"
            path.write_bytes(records)
            result = metrics.transfer_metrics(path)
        self.assertEqual(result["bytestream_uploaded_bytes"], 1000)
        self.assertEqual(result["bytestream_downloaded_bytes"], 256)


if __name__ == "__main__":
    unittest.main()
