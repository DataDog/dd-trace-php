#!/usr/bin/env python3
"""Guard the checked-in CMake inventories and native dependency boundaries."""

import hashlib
import json
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent


class ManifestTest(unittest.TestCase):
    def test_pinned_complete_inventories(self):
        for arch in ["x86_64", "aarch64"]:
            for libc in ["glibc", "musl"]:
                with self.subTest(arch=arch, libc=libc):
                    text = (HERE / "manifests" / (arch + "_" + libc + ".bzl")).read_text()
                    data = json.loads(text.split("RUNTIME = ", 1)[1])
                    self.assertNotIn("/home/", text)
                    self.assertNotIn("/tmp/", text)
                    self.assertEqual(data["source_sha256"], json.loads((HERE / "sources.json").read_text())["source"]["sha256"])
                    for filename, digest in data["reference_sha256"].items():
                        self.assertEqual(hashlib.sha256((HERE / filename).read_bytes()).hexdigest(), digest)
                    builtins = data["builtins"]
                    self.assertGreater(len(builtins["objects"]), 150)
                    crt = [src for _, src, _, _ in builtins["objects"] if src.endswith(("/crtbegin.c", "/crtend.c"))]
                    self.assertEqual(len(crt), 2)
                    if arch == "aarch64":
                        atomics = [o for o in builtins["objects"] if o[1].endswith("aarch64/lse.S")]
                        self.assertGreater(len(atomics), 50)
                        self.assertGreater(len({o[2] for o in atomics}), 50)
                    for stage in ["builtins", "runtimes"]:
                        inventory = data[stage]
                        outputs = {o[0] for o in inventory["objects"]} | {o[0] for o in inventory["links"]}
                        for _, _, args in inventory["links"]:
                            for arg in args:
                                if arg.startswith("@BUILD@/"):
                                    self.assertIn(arg, outputs)
                    runtime = data["runtimes"]
                    self.assertEqual(bool([o for o in runtime["links"] if "libclang_rt.asan-" in o[0]]), libc == "glibc")
                    self.assertIn("include/c++/v1/__config_site", runtime["generated"])


if __name__ == "__main__":
    unittest.main()
