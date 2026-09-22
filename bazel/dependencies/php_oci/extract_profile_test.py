#!/usr/bin/env python3
"""Focused safety regressions for the OCI profile extractor."""

from __future__ import annotations

import io
import hashlib
import importlib.util
import os
import tarfile
import tempfile
import unittest
from unittest import mock
from pathlib import Path


MODULE_PATH = Path(__file__).with_name("extract_profile.py")
SPEC = importlib.util.spec_from_file_location("extract_profile", MODULE_PATH)
assert SPEC and SPEC.loader
extract_profile = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(extract_profile)


def tar_member(archive: tarfile.TarFile, name: str, kind: bytes, *, link: str = "", data: bytes = b"") -> None:
    member = tarfile.TarInfo(name)
    member.type = kind
    member.linkname = link
    member.size = len(data)
    archive.addfile(member, io.BytesIO(data) if data else None)


def write_tar(path: Path, entries: list[tuple[str, bytes, str, bytes]]) -> None:
    with tarfile.open(path, "w") as archive:
        for name, kind, link, data in entries:
            tar_member(archive, name, kind, link=link, data=data)


def apply(path: Path, destination: Path, semantics: str = "profile_copy_snapshot") -> None:
    extract_profile.apply_layer(
        path,
        destination,
        "opt/php/8.0",
        "full_profile",
        [0, 1024 * 1024],
        semantics,
    )


class ExtractProfileSafetyTest(unittest.TestCase):
    def test_chained_symlink_parent_cannot_escape_profile(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / "traversal.tar"
            write_tar(archive, [
                ("opt/php/8.0/a", tarfile.DIRTYPE, "", b""),
                ("opt/php/8.0/a/b", tarfile.SYMTYPE, "../z", b""),
                ("opt/php/8.0/a/b/c", tarfile.SYMTYPE, "../../x", b""),
                ("opt/php/8.0/a/b/c/proof", tarfile.REGTYPE, "", b"escaped"),
            ])
            profile = root / "profile"
            profile.mkdir()
            with self.assertRaises(SystemExit):
                apply(archive, profile)
            self.assertFalse((root / "x" / "proof").exists())

    def test_image_root_opaque_whiteout_affects_selected_profile(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / "root-opaque.tar"
            write_tar(archive, [(".wh..wh..opq", tarfile.REGTYPE, "", b"")])
            profile = root / "profile"
            profile.mkdir()
            with self.assertRaises(SystemExit):
                apply(archive, profile)

    def test_copy_snapshot_root_opaque_marker_is_allowed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / "copy-root-opaque.tar"
            write_tar(archive, [
                ("opt/php/8.0/.wh..wh..opq", tarfile.REGTYPE, "", b""),
                ("opt/php/8.0/include/proof.h", tarfile.REGTYPE, "", b"proof"),
            ])
            profile = root / "profile"
            profile.mkdir()
            apply(archive, profile)
            self.assertEqual((profile / "include" / "proof.h").read_bytes(), b"proof")

    def test_harmless_dot_root_directory_is_ignored(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / "dot-root.tar"
            write_tar(archive, [
                ("./", tarfile.DIRTYPE, "", b""),
                ("opt/php/8.0/include/proof.h", tarfile.REGTYPE, "", b"proof"),
            ])
            profile = root / "profile"
            profile.mkdir()
            apply(archive, profile)
            self.assertEqual((profile / "include" / "proof.h").read_bytes(), b"proof")


def write_abi_profile(root: Path, *, php_api: int = 20250925, zend_module_api: int = 20250925,
                      zend_extension_api: int = 420250925, debug: int = 0, zts: int = 0) -> None:
    main = root / "include" / "php" / "main"
    zend = root / "include" / "php" / "Zend"
    binary = root / "bin"
    main.mkdir(parents=True)
    zend.mkdir(parents=True)
    binary.mkdir()
    (main / "php_version.h").write_text(
        '#define PHP_VERSION "8.5.8RC1"\n#define PHP_VERSION_ID 80508\n', encoding="utf-8"
    )
    (main / "php.h").write_text(f"#define PHP_API_VERSION {php_api}\n", encoding="utf-8")
    (main / "php_config.h").write_text(
        f"#define ZEND_DEBUG {debug}\n#define ZTS {zts}\n", encoding="utf-8"
    )
    (zend / "zend.h").write_text("/* fixture */\n", encoding="utf-8")
    (zend / "zend_modules.h").write_text(
        f"#define ZEND_MODULE_API_NO {zend_module_api}\n", encoding="utf-8"
    )
    (zend / "zend_extensions.h").write_text(
        f"#define ZEND_EXTENSION_API_NO {zend_extension_api}\n", encoding="utf-8"
    )
    (binary / "php").write_bytes(b"ELF fixture")


def expected_abi(**updates) -> dict:
    expected = {
        "php_version": "8.5.8RC1",
        "php_version_id": 80508,
        "php_api": 20250925,
        "zend_module_api": 20250925,
        "zend_extension_api": 420250925,
        "arch": "amd64",
        "debug": False,
        "zts": False,
        "asan": False,
        "required_extensions": [],
    }
    expected.update(updates)
    return expected


class ExtractProfileAbiTest(unittest.TestCase):
    def require(self, profile: Path, expected: dict, *, machine: int = 62, needed=()) -> dict:
        with mock.patch.object(extract_profile, "elf_needed", return_value=(b"", machine, list(needed))):
            return extract_profile.require_profile(profile, expected, "headers")

    def test_records_php_and_zend_apis(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            profile = Path(temporary)
            write_abi_profile(profile)
            observed = self.require(profile, expected_abi())
            self.assertEqual(observed["php_api"], 20250925)
            self.assertEqual(observed["zend_module_api"], 20250925)
            self.assertEqual(observed["zend_extension_api"], 420250925)

    def test_rejects_tampered_abi_headers(self) -> None:
        cases = (
            {"php_api": 1},
            {"zend_module_api": 1},
            {"zend_extension_api": 1},
            {"debug": 1},
            {"zts": 1},
        )
        for changes in cases:
            with self.subTest(changes=changes), tempfile.TemporaryDirectory() as temporary:
                profile = Path(temporary)
                write_abi_profile(profile, **changes)
                with self.assertRaises(SystemExit):
                    self.require(profile, expected_abi())

    def test_rejects_wrong_elf_architecture(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            profile = Path(temporary)
            write_abi_profile(profile)
            with self.assertRaises(SystemExit):
                self.require(profile, expected_abi(), machine=183)

    def test_rejects_missing_or_unexpected_asan_runtime(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            profile = Path(temporary)
            write_abi_profile(profile)
            with self.assertRaises(SystemExit):
                self.require(profile, expected_abi(asan=True), needed=())
            with self.assertRaises(SystemExit):
                self.require(profile, expected_abi(), needed=("libclang_rt.asan-x86_64.so",))


class OutputNormalizationTest(unittest.TestCase):
    def normalized_entries(self, umask: int) -> list[tuple[str, str, int, int, str]]:
        with tempfile.TemporaryDirectory() as temporary:
            previous = os.umask(umask)
            try:
                root = Path(temporary) / "normalized"
                (root / "sdk" / "bin").mkdir(parents=True, mode=0o755)
                (root / "sdk" / ".root").write_text("marker\n", encoding="utf-8")
                executable = root / "sdk" / "bin" / "php-config"
                executable.write_text("tool\n", encoding="utf-8")
                executable.chmod(0o755)
                (root / "observed.json").write_text("{}\n", encoding="utf-8")
            finally:
                os.umask(previous)
            extract_profile.normalize_tree(root)
            entries = []
            for path in [root, *sorted(root.rglob("*"))]:
                relative = "." if path == root else path.relative_to(root).as_posix()
                if path.is_dir():
                    kind, content = "directory", ""
                else:
                    kind = "file"
                    content = hashlib.sha256(path.read_bytes()).hexdigest()
                entries.append((relative, kind, path.stat().st_mode & 0o777, int(path.stat().st_mtime), content))
            return entries

    def test_output_modes_are_independent_of_umask(self) -> None:
        permissive = self.normalized_entries(0o022)
        restrictive = self.normalized_entries(0o077)
        self.assertEqual(permissive, restrictive)
        self.assertEqual({entry[0]: entry[2] for entry in permissive}, {
            ".": 0o755,
            "observed.json": 0o644,
            "sdk": 0o755,
            "sdk/.root": 0o644,
            "sdk/bin": 0o755,
            "sdk/bin/php-config": 0o755,
        })
        self.assertEqual({entry[3] for entry in permissive}, {0})

if __name__ == "__main__":
    unittest.main()
