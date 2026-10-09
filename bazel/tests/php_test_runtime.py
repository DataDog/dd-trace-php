"""Run the locked PHP image without consulting host PHP or host libraries."""

import os
from pathlib import Path
import subprocess


def runfile(relative):
    path = Path(relative)
    if path.is_absolute():
        raise ValueError(f"expected runfile-relative path: {relative}")
    return Path(os.environ["TEST_SRCDIR"]) / os.environ["TEST_WORKSPACE"] / path


def php_command(paths, load_extension=True):
    loader, lib_root, php, extension, curl = map(runfile, paths)
    if not all(path.is_file() for path in (loader, lib_root, php, extension, curl)):
        raise ValueError(f"missing declared runtime input: {paths}")
    dependencies = sorted((curl.parent.parent / "dependencies").glob("**/*.so*"))
    library_roots = list(dict.fromkeys([lib_root.parent, curl.parent] + [path.parent for path in dependencies]))
    search_path = ":".join(str(path) for path in library_roots)
    for executable in (php, extension):
        listed = subprocess.run(
            [str(loader), "--inhibit-cache", "--list", "--library-path", search_path, str(executable)],
            capture_output=True, text=True, check=True, env=clean_environment(),
        )
        resolved = []
        for line in listed.stdout.splitlines():
            if " => not found" in line:
                raise RuntimeError(f"undeclared ELF dependency: {line}")
            if " => " in line:
                path = Path(line.split(" => ", 1)[1].split(" (", 1)[0])
                if not any(path.is_relative_to(root) for root in library_roots):
                    raise RuntimeError(f"host library resolved: {path}")
                resolved.append(path)
        if not resolved:
            raise RuntimeError(f"no declared ELF libraries resolved for {executable}")
    command = [str(loader), "--inhibit-cache", "--library-path", search_path, str(php), "-n", "-d", f"extension={extension}"]
    mappings = subprocess.run(
        command + ["-r", "echo file_get_contents('/proc/self/maps');"],
        capture_output=True, text=True, check=True, env=clean_environment(),
    )
    if mappings.stderr or not mappings.stdout.strip():
        raise RuntimeError(f"PHP could not report mapped libraries: {mappings.stderr}")
    allowed_roots = [path.resolve().parent for path in [lib_root, curl, php, extension] + dependencies]
    allowed_files = {path.resolve() for path in (loader, php, extension)}
    for line in mappings.stdout.splitlines():
        fields = line.split()
        if not fields:
            continue
        mapped = fields[-1]
        if not mapped.startswith("/"):
            continue
        path = Path(mapped).resolve()
        if path.name.endswith(".so") or ".so." in path.name or path in allowed_files:
            if path not in allowed_files and not any(path.is_relative_to(root) for root in allowed_roots):
                raise RuntimeError(f"PHP mapped a host library: {path}")
    return command if load_extension else command[:-2]


def clean_environment():
    return {
        "DD_APPSEC_ENABLED": "false",
        "DD_PROFILING_ENABLED": "false",
        "DD_TRACE_AGENT_URL": "http://127.0.0.1:1",
        "HOME": os.environ["TEST_TMPDIR"],
        "LANG": "C",
        "LC_ALL": "C",
        "TZ": "UTC",
    }
