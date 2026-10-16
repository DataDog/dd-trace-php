"""Execute the complete tracer PHPT tree with the matching PHP source runner."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys

from bazel.tests.php_test_runtime import clean_environment, php_command, runfile


def require_isolation():
    if os.environ.get("PHPT_UNSAFE_ALLOW_PROCESSWRAPPER") == "1":
        return
    try:
        isolated = os.readlink("/proc/self/ns/mnt") != os.readlink("/proc/1/ns/mnt")
    except OSError as error:
        raise RuntimeError(
            "cannot verify an isolated mount namespace for the full PHPT suite; "
            "use an isolated worker, or explicitly set --test_env=PHPT_UNSAFE_ALLOW_PROCESSWRAPPER=1 "
            "to accept host-side effects"
        ) from error
    if not isolated:
        raise RuntimeError(
            "full PHPT suite requires an isolated mount namespace: tests may modify /var/run/datadog "
            "and invoke sudo. Use a sandboxed worker, or explicitly set "
            "--test_env=PHPT_UNSAFE_ALLOW_PROCESSWRAPPER=1 to accept host-side effects"
        )


def selected_tests(root, selections):
    if not selections:
        return [str(root)]
    selected = []
    for selection in selections:
        source = (root.parent.parent / selection).resolve()
        if not source.is_relative_to(root) or not source.is_file() or source.suffix != ".phpt":
            raise ValueError(f"not a declared tracer PHPT: {selection}")
        selected.append(str(source))
    return selected


def main():
    require_isolation()
    runtime = sys.argv[1:6]
    runner, php_executable, fixture = map(runfile, sys.argv[6:9])
    source = fixture.parent
    destination = Path(os.environ["TEST_TMPDIR"]) / "tests" / "ext"
    shutil.copytree(source, destination)
    test_files = list(destination.rglob("*.phpt"))
    if not test_files:
        raise ValueError("PHPT tree contains no declared tests")
    environment = clean_environment()
    for name in ("TEST_SRCDIR", "TEST_WORKSPACE", "RUNFILES_DIR", "RUNFILES_MANIFEST_FILE", "PYTHONPATH"):
        if name in os.environ:
            environment[name] = os.environ[name]
    php = php_command(runtime, load_extension=False)
    environment.update({
        "DD_TRACE_GIT_METADATA_ENABLED": "0",
        "NO_INTERACTION": "1",
        "PHPT_PHP_COMMAND": json.dumps(php),
        "REPORT_EXIT_STATUS": "1",
        "SKIP_ONLINE_TESTS": "1",
        "TEMP": os.environ["TEST_TMPDIR"],
        "TMPDIR": os.environ["TEST_TMPDIR"],
        "TEST_PHP_SRCDIR": str(destination.parent.parent),
        "TEST_PHP_JUNIT": str(Path(os.environ["TEST_UNDECLARED_OUTPUTS_DIR"]) / "phpt.xml"),
    })
    extension = runfile(runtime[3])
    command = php + [
        str(runner), "-n", "-d", f"extension={extension}",
        "-p", str(php_executable), "-q", "-j1", "--offline", "--no-color", "--no-progress",
        "--show-diff", "--set-timeout", "30",
    ] + selected_tests(destination, sys.argv[9:])
    print(f"Declared tracer PHPT files: {len(test_files)}", flush=True)
    result = subprocess.run(command, cwd=destination.parent.parent, env=environment)
    if result.returncode:
        raise SystemExit(result.returncode)


if __name__ == "__main__":
    main()
