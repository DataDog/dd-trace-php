import json
import pathlib
import tempfile
import unittest

from importlib.machinery import SourceFileLoader


VALIDATOR = SourceFileLoader(
    "validate_php_sdk_metadata",
    str(pathlib.Path(__file__).with_name("validate-php-sdk-metadata.py")),
).load_module()


class ValidateMetadataTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        root = pathlib.Path(self.directory.name)
        self.observed = root / "observed.json"
        self.lock = root / "lock.json"
        self.observed_data = {
            "php_version": "8.5.8RC1",
            "php_api": 20250925,
            "zend_module_api": 20250925,
            "zend_extension_api": 420250925,
            "arch": "arm64",
            "debug": True,
            "zts": True,
            "asan": True,
            "content_mode": "headers",
        }
        self.lock_data = {
            "image_family": "bookworm",
            "abi_profile": "debug-zts-asan",
            "index_digest": "sha256:0123456789",
            "target_libc": "glibc",
            "target_triple": "aarch64-unknown-linux-gnu",
        }
        self.args = [
            "validator",
            str(self.observed),
            str(self.lock),
            "8.5.8RC1",
            "20250925",
            "20250925",
            "420250925",
            "arm64",
            "true",
            "true",
            "true",
            "bookworm",
            "debug-zts-asan",
            "sha256:0123456789",
            "glibc",
            "aarch64-unknown-linux-gnu",
        ]

    def tearDown(self):
        self.directory.cleanup()

    def write(self):
        self.observed.write_text(json.dumps(self.observed_data), encoding="utf-8")
        self.lock.write_text(json.dumps(self.lock_data), encoding="utf-8")

    def test_exact_metadata_passes(self):
        self.write()
        VALIDATOR.validate(self.args)

    def test_truncated_extension_api_is_rejected(self):
        self.write()
        self.args[6] = "4"
        with self.assertRaisesRegex(ValueError, "zend_extension_api"):
            VALIDATOR.validate(self.args)

    def test_stale_version_and_profile_are_rejected(self):
        self.write()
        self.args[3] = "8.5.7"
        self.args[12] = "debug-zts"
        with self.assertRaisesRegex(ValueError, "php_version") as caught:
            VALIDATOR.validate(self.args)
        self.assertIn("abi_profile", str(caught.exception))


if __name__ == "__main__":
    unittest.main()
