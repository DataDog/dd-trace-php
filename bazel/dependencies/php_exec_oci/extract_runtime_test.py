#!/usr/bin/env python3
"""Focused OCI layer semantics tests for the execution-PHP importer."""

import io
import tarfile
import tempfile
import unittest
from pathlib import Path

import extract_runtime


def write_layer(path: Path, entries: list[tuple[str, bytes]]) -> None:
    with tarfile.open(path, "w") as archive:
        for name, contents in entries:
            member = tarfile.TarInfo(name)
            member.mode = 0o644
            member.size = len(contents)
            archive.addfile(member, io.BytesIO(contents))


class ApplyLayerTest(unittest.TestCase):
    def test_opaque_whiteout_clears_selected_library_directory(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "root"
            root.mkdir()
            first = base / "first.tar"
            second = base / "second.tar"
            write_layer(first, [
                ("usr/lib/libold.so", b"old"),
                ("usr/lib/libstale.so.1", b"stale"),
            ])
            write_layer(second, [
                ("usr/lib/.wh..wh..opq", b""),
                ("usr/lib/libnew.so", b"new"),
            ])
            budget = [0, 1024]

            extract_runtime.apply_layer(first, root, "opt/php", budget)
            extract_runtime.apply_layer(second, root, "opt/php", budget)

            self.assertEqual(
                ["libnew.so"],
                sorted(path.name for path in (root / "usr/lib").iterdir()),
            )

    def test_directory_whiteout_removes_selected_descendants(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "root"
            root.mkdir()
            first = base / "first.tar"
            second = base / "second.tar"
            write_layer(first, [("usr/lib/libold.so", b"old")])
            write_layer(second, [("usr/.wh.lib", b"")])
            budget = [0, 1024]

            extract_runtime.apply_layer(first, root, "opt/php", budget)
            extract_runtime.apply_layer(second, root, "opt/php", budget)

            self.assertFalse((root / "usr/lib").exists())

    def test_top_level_directory_whiteout_removes_selected_descendants(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "root"
            root.mkdir()
            first = base / "first.tar"
            second = base / "second.tar"
            write_layer(first, [("usr/lib/libold.so", b"old")])
            write_layer(second, [(".wh.usr", b"")])

            extract_runtime.apply_layer(first, root, "opt/php", [0, 1024])
            extract_runtime.apply_layer(second, root, "opt/php", [0, 1024])

            self.assertFalse((root / "usr").exists())

    def test_selected_ancestor_symlink_is_preserved(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            root = base / "root"
            root.mkdir()
            layer = base / "layer.tar"
            with tarfile.open(layer, "w") as archive:
                link = tarfile.TarInfo("lib")
                link.type = tarfile.SYMTYPE
                link.linkname = "usr/lib"
                archive.addfile(link)
                library = tarfile.TarInfo("usr/lib/libexample.so")
                library.mode = 0o755
                library.size = 7
                archive.addfile(library, io.BytesIO(b"example"))

            extract_runtime.apply_layer(layer, root, "opt/php", [0, 1024])

            self.assertTrue((root / "lib").is_symlink())
            self.assertEqual("usr/lib", (root / "lib").readlink().as_posix())

    def test_different_content_library_candidates_are_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "lib").mkdir()
            (root / "usr/lib").mkdir(parents=True)
            (root / "lib/libduplicate.so").write_bytes(b"first")
            (root / "usr/lib/libduplicate.so").write_bytes(b"second")

            with self.assertRaisesRegex(SystemExit, "ambiguous different-content OCI libraries"):
                extract_runtime.find_library(root, "libduplicate.so", "opt/php")

    def test_transitive_asan_dependency_is_rejected(self) -> None:
        with self.assertRaisesRegex(SystemExit, "unexpectedly depends on ASan"):
            extract_runtime.validate_stable_dependencies(["libc.so.6", "libclang_rt.asan.so"])


if __name__ == "__main__":
    unittest.main()
