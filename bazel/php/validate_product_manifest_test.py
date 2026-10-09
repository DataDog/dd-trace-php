#!/usr/bin/env python3
"""Regression checks for required concrete product-manifest records."""

import json
import pathlib
import subprocess
import sys
import tempfile
import unittest


_HERE = pathlib.Path(__file__).resolve().parent
_VALIDATOR = _HERE / "validate_product_manifest.py"


class ProductManifestValidatorTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        root = pathlib.Path(self.directory.name)
        self.file = root / "artifact"
        self.file.write_text("artifact", encoding="utf-8")
        self.declared = root / "declared.json"
        self.built = root / "built.json"
        self.declared.write_text(json.dumps({
            "schema_version": 1,
            "rows": [{
                "products": {"tracer": {"supported": True}},
                "product_build_keys": {"tracer": "php_8_5_amd64_glibc_bookworm_nts"},
                "build_artifact_roles": {"tracer": {
                    "fat_binary": "tracer/fat",
                    "fat_debug": "tracer/fat/debug",
                }},
                "build_artifacts": {"tracer": ["tracer/fat", "tracer/fat/debug"]},
                "validation_bundle_artifact_roles": {"tracer": {
                    "fat_binary": "validation/fat",
                    "fat_debug": "validation/fat.debug",
                }},
                "validation_bundle_artifacts": {"tracer": ["validation/fat", "validation/fat.debug"]},
                "asan_runtime_scope_key": "asan_runtime_amd64_glibc_asan",
                "asan_runtime_build_artifact_roles": {"shared": "runtime/asan/shared"},
                "asan_runtime_build_artifacts": ["runtime/asan/shared"],
                "asan_runtime_validation_artifact_roles": {"shared": "validation/runtime/asan.so"},
                "asan_runtime_validation_artifacts": ["validation/runtime/asan.so"],
            }],
        }), encoding="utf-8")

    def tearDown(self):
        self.directory.cleanup()

    def _built_manifest(self):
        return {
            "schema_version": 1,
            "build_artifacts": [
                {"identity": "tracer/fat", "path": str(self.file), "product": "tracer", "scope_key": "php_8_5_amd64_glibc_bookworm_nts", "role": "fat_binary"},
                {"identity": "tracer/fat/debug", "path": str(self.file), "product": "tracer", "scope_key": "php_8_5_amd64_glibc_bookworm_nts", "role": "fat_debug"},
            ],
            "validation_bundle_artifacts": [
                {"destination": "validation/fat", "path": str(self.file), "product": "tracer", "scope_key": "php_8_5_amd64_glibc_bookworm_nts", "role": "fat_binary"},
                {"destination": "validation/fat.debug", "path": str(self.file), "product": "tracer", "scope_key": "php_8_5_amd64_glibc_bookworm_nts", "role": "fat_debug"},
            ],
            "asan_runtime_build_artifacts": [
                {"identity": "runtime/asan/shared", "path": str(self.file), "product": "asan_runtime", "scope_key": "asan_runtime_amd64_glibc_asan", "role": "shared"},
            ],
            "asan_runtime_validation_artifacts": [
                {"destination": "validation/runtime/asan.so", "path": str(self.file), "product": "asan_runtime", "scope_key": "asan_runtime_amd64_glibc_asan", "role": "shared"},
            ],
        }

    def _run(self, manifest):
        self.built.write_text(json.dumps(manifest), encoding="utf-8")
        return subprocess.run(
            [sys.executable, str(_VALIDATOR), str(self.declared), str(self.built)],
            check=False,
            capture_output=True,
            text=True,
        )

    def test_accepts_complete_concrete_records(self):
        self.assertEqual(self._run(self._built_manifest()).returncode, 0)

    def test_rejects_missing_debug_record(self):
        manifest = self._built_manifest()
        manifest["build_artifacts"].pop()
        self.assertNotEqual(self._run(manifest).returncode, 0)

    def test_rejects_missing_asan_runtime_record(self):
        manifest = self._built_manifest()
        manifest["asan_runtime_validation_artifacts"] = []
        self.assertNotEqual(self._run(manifest).returncode, 0)

    def test_rejects_nonexistent_file(self):
        manifest = self._built_manifest()
        manifest["build_artifacts"][0]["path"] = str(self.file.with_name("missing"))
        self.assertNotEqual(self._run(manifest).returncode, 0)

    def test_rejects_duplicate_role_binding(self):
        manifest = self._built_manifest()
        duplicate = dict(manifest["build_artifacts"][0])
        duplicate["identity"] = "tracer/other"
        manifest["build_artifacts"].append(duplicate)
        self.assertNotEqual(self._run(manifest).returncode, 0)

    def test_accepts_platform_producer_reused_by_row_destinations(self):
        scope = "loader_amd64_glibc_none"
        runtime_scope = "asan_runtime_amd64_glibc_asan"
        self.declared.write_text(json.dumps({
            "schema_version": 1,
            "rows": [
                {
                    "products": {"loader": {"supported": True}},
                    "product_build_keys": {"loader": scope},
                    "build_artifact_roles": {"loader": {"binary": "loader/amd64"}},
                    "validation_bundle_artifact_roles": {"loader": {"binary": "validation/php70/loader.so"}},
                    "asan_runtime_scope_key": runtime_scope,
                    "asan_runtime_build_artifact_roles": {"shared": "runtime/asan/amd64/shared"},
                    "asan_runtime_validation_artifact_roles": {"shared": "validation/php70/asan.so"},
                },
                {
                    "products": {"loader": {"supported": True}},
                    "product_build_keys": {"loader": scope},
                    "build_artifact_roles": {"loader": {"binary": "loader/amd64"}},
                    "validation_bundle_artifact_roles": {"loader": {"binary": "validation/php71/loader.so"}},
                    "asan_runtime_scope_key": runtime_scope,
                    "asan_runtime_build_artifact_roles": {"shared": "runtime/asan/amd64/shared"},
                    "asan_runtime_validation_artifact_roles": {"shared": "validation/php71/asan.so"},
                },
            ],
        }), encoding="utf-8")
        record = {"path": str(self.file), "product": "loader", "scope_key": scope, "role": "binary"}
        runtime = {"path": str(self.file), "product": "asan_runtime", "scope_key": runtime_scope, "role": "shared"}
        manifest = {
            "schema_version": 1,
            "build_artifacts": [dict(record, identity="loader/amd64")],
            "validation_bundle_artifacts": [
                dict(record, destination="validation/php70/loader.so"),
                dict(record, destination="validation/php71/loader.so"),
            ],
            "asan_runtime_build_artifacts": [dict(runtime, identity="runtime/asan/amd64/shared")],
            "asan_runtime_validation_artifacts": [
                dict(runtime, destination="validation/php70/asan.so"),
                dict(runtime, destination="validation/php71/asan.so"),
            ],
        }
        self.assertEqual(self._run(manifest).returncode, 0)


if __name__ == "__main__":
    unittest.main()
