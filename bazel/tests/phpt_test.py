"""Strict, deliberately small PHPT subset (no silent skips or host tools)."""

import os
from pathlib import Path
import re
import subprocess
import sys

from bazel.tests.php_test_runtime import clean_environment, php_command, runfile


def sections(source):
    content = source.read_text()
    matches = list(re.finditer(r"^--([A-Z_]+)--\s*$", content, re.MULTILINE))
    result = {}
    for index, match in enumerate(matches):
        key = match.group(1)
        if key in result or key not in {"TEST", "FILE", "EXPECT", "INI", "ENV"}:
            raise ValueError(f"unsupported or duplicate PHPT section: {key} in {source}")
        end = matches[index + 1].start() if index + 1 < len(matches) else len(content)
        result[key] = content[match.end():end].lstrip("\n")
    if not {"TEST", "FILE", "EXPECT"}.issubset(result):
        raise ValueError(f"incomplete PHPT: {source}")
    return result


def main():
    *runtime, phpt = sys.argv[1:]
    source = runfile(phpt)
    test = sections(source)
    script = Path(os.environ["TEST_TMPDIR"]) / (source.stem + ".php")
    script.write_text(test["FILE"])
    environment = clean_environment()
    for line in test.get("ENV", "").splitlines():
        if line.strip():
            name, value = line.split("=", 1)
            environment[name] = value
    ini = []
    for line in test.get("INI", "").splitlines():
        if line.strip():
            ini.extend(["-d", line.strip()])
    result = subprocess.run(php_command(runtime) + ini + [str(script)],
                            env=environment, capture_output=True, text=True, timeout=30)
    expected = test["EXPECT"].rstrip("\r\n")
    actual = result.stdout.rstrip("\r\n")
    if result.returncode or actual != expected or result.stderr:
        raise AssertionError(f"{source}: exit={result.returncode}\nexpected:\n{expected!r}\n"
                             f"actual:\n{actual!r}\nstderr:\n{result.stderr}")


if __name__ == "__main__":
    main()
