import io
import json
import tarfile
import tempfile
import unittest
from pathlib import Path

from bazel.dependencies.target_curl_oci import extract_curl


class CurlLayerTest(unittest.TestCase):
    def test_rejects_parent_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "layer.tgz"
            with tarfile.open(archive, "w:gz") as layer:
                member = tarfile.TarInfo("usr/local/curl/lib/libcurl.so.4")
                member.type = tarfile.SYMTYPE
                member.linkname = "../../../../etc/passwd"
                layer.addfile(member)
            with self.assertRaises(SystemExit):
                extract_curl.extract(archive, Path(tmp) / "out")

    def test_ignores_files_outside_prefix(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "layer.tgz"
            with tarfile.open(archive, "w:gz") as layer:
                content = b"host file"
                member = tarfile.TarInfo("etc/passwd")
                member.size = len(content)
                layer.addfile(member, io.BytesIO(content))
            output = Path(tmp) / "out"
            output.mkdir()
            extract_curl.extract(archive, output)
            self.assertEqual(list(output.rglob("*")), [])


if __name__ == "__main__":
    unittest.main()
