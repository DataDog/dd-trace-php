#!/usr/bin/env -S uv run --script

"""Build a slim dd-library-php tarball for system-tests.

Contains only one PHP version / thread-safety variant. No stub files needed —
datadog-setup.php gracefully skips missing ABIs and products.

Usage:
    build-slim-package.py <php_version> [options]

Options:
    --nts | --zts           Thread safety (default: nts)
    --arch <arch>            Target architecture: aarch64 or x86_64 (default: host)
    --appsec                Build appsec extension + both helpers
    --profiler              Build profiler extension
    --output-dir <dir>      Output directory (default: .)

Examples:
    build-slim-package.py 8.2
    build-slim-package.py 8.2 --appsec --profiler
    build-slim-package.py 8.2 --output-dir /tmp/out

The tarball can be used directly with system-tests:
    cp <tarball> datadog-setup.php system-tests/binaries/
    cd system-tests && WEBLOG_VARIANT=apache-mod-8.2 ./build.sh php
"""

import argparse
import os
import platform
import shutil
import subprocess
import sys
import tarfile
import tempfile
import threading
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent.parent

PHP_API_MAP = {
    "7.0": "20151012",
    "7.1": "20160303",
    "7.2": "20170718",
    "7.3": "20180731",
    "7.4": "20190902",
    "8.0": "20200930",
    "8.1": "20210902",
    "8.2": "20220829",
    "8.3": "20230831",
    "8.4": "20240924",
    "8.5": "20250925",
}

DOCKERH = str(SCRIPT_DIR / "dockerh")
DOCKER_UPPER_CP = str(SCRIPT_DIR / "docker-upper-cp")


def get_arch():
    m = platform.machine()
    return normalise_arch(m)


def normalise_arch(s):
    s = s.lower()
    if s in ("arm64", "aarch64", "arm", "aarch"):
        return "aarch64"
    if s in ("x86_64", "x64", "amd64", "x86"):
        return "x86_64"
    sys.exit(f"Error: unknown architecture '{s}'. Use aarch64 or x86_64.")


def git_rev(*args):
    return subprocess.check_output(
        ["git", "rev-parse", *args], cwd=REPO_ROOT, text=True
    ).strip()


class Build:
    """A single build job that runs in a background thread."""

    def __init__(self, name, cmd, logfile):
        self.name = name
        self.cmd = cmd
        self.logfile = logfile
        self.returncode = None
        self.thread = None

    def start(self):
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def _run(self):
        with open(self.logfile, "w") as f:
            p = subprocess.run(self.cmd, stdout=f, stderr=subprocess.STDOUT, cwd=REPO_ROOT)
            self.returncode = p.returncode

    def wait(self):
        self.thread.join()
        return self.returncode == 0


def main():
    parser = argparse.ArgumentParser(
        description="Build a slim dd-library-php tarball for system-tests."
    )
    parser.add_argument("php_version", help="PHP version (e.g. 8.2)")
    ts_group = parser.add_mutually_exclusive_group()
    ts_group.add_argument("--nts", action="store_const", const="nts", dest="thread_safety")
    ts_group.add_argument("--zts", action="store_const", const="zts", dest="thread_safety")
    parser.add_argument("--arch", default=None, help="Target architecture: aarch64 or x86_64 (default: host)")
    parser.add_argument("--appsec", action="store_true", help="Build appsec extension + both helpers")
    parser.add_argument("--profiler", action="store_true", help="Build profiler extension")
    parser.add_argument("--output-dir", default=".", help="Output directory (default: .)")
    parser.set_defaults(thread_safety="nts")
    args = parser.parse_args()

    php_version = args.php_version
    if php_version not in PHP_API_MAP:
        sys.exit(f"Error: unsupported PHP version '{php_version}'. Supported: {', '.join(sorted(PHP_API_MAP))}")

    php_api = PHP_API_MAP[php_version]
    thread_safety = args.thread_safety
    ext_suffix = "-zts" if thread_safety == "zts" else ""
    arch = normalise_arch(args.arch) if args.arch else get_arch()
    version = (REPO_ROOT / "VERSION").read_text().strip()
    build_appsec = args.appsec
    build_profiler = args.profiler

    ci_sha = git_rev("HEAD")
    ci_branch = git_rev("--abbrev-ref", "HEAD")

    logdir = tempfile.mkdtemp(prefix="slim-build-")
    print(f"Build logs: {logdir}")

    cp = f"st-slim-{arch}"
    centos_image = f"datadog/dd-trace-ci:php-{php_version}_centos-7"
    ci_env = ["-e", f"CI_COMMIT_SHA={ci_sha}", "-e", f"CI_COMMIT_BRANCH={ci_branch}"]

    # Docker --platform flag for cross-architecture builds.
    docker_platform = "linux/arm64" if arch == "aarch64" else "linux/amd64"
    platform_args = ["--platform", docker_platform]

    # ── Launch builds ─────────────────────────────────────────────────────
    builds = []

    # Tracer (always)
    builds.append(Build("tracer", [
        DOCKERH, "--cache", f"{cp}-tracer-{php_version}-{thread_safety}",
        "--overlayfs", "--php", thread_safety,
        centos_image,
        *platform_args,
        "--", "bash", "-c",
        "export CARGO_HOME=$PWD/tmp/cargo_home; make -j$(nproc)",
    ], os.path.join(logdir, "tracer.log")))

    if build_appsec:
        # Appsec extension
        # Clean appsec output dir first — build-appsec.sh runs objcopy on
        # all .so files it finds, and stale stubs from the working tree
        # (visible through the overlay) cause it to fail on empty files.
        builds.append(Build("appsec-ext", [
            DOCKERH, "--cache", f"{cp}-appsec-{php_version}",
            "--overlayfs", "--root",
            centos_image,
            *ci_env, *platform_args,
            "--", "bash", "-c",
            f"rm -f appsec_$(uname -m)/*.so; PHP_VERSION={php_version} bash .gitlab/build-appsec.sh",
        ], os.path.join(logdir, "appsec-ext.log")))

        # C++ helper
        builds.append(Build("appsec-helper-cpp", [
            DOCKERH, "--cache", f"{cp}-helper-cpp",
            "--overlayfs",
            "registry.ddbuild.io/images/mirror/b1o7r7e0/nginx_musl_toolchain",
            *ci_env, *platform_args,
            "--", "bash", ".gitlab/build-appsec-helper.sh",
        ], os.path.join(logdir, "appsec-helper-cpp.log")))

        # Rust helper
        builds.append(Build("appsec-helper-rust", [
            DOCKERH, "--cache", f"{cp}-helper-rust",
            "--overlayfs",
            "datadog/dd-appsec-php-ci:nginx-fpm-php-8.5-release-musl",
            *ci_env, *platform_args,
            "--", "bash", ".gitlab/build-appsec-helper-rust.sh",
        ], os.path.join(logdir, "appsec-helper-rust.log")))

    if build_profiler:
        builds.append(Build("profiler", [
            DOCKERH, "--cache", f"{cp}-profiler-{php_version}",
            "--overlayfs", "--root",
            centos_image,
            *ci_env, *platform_args,
            "--", "bash", "-c",
            f"PHP_VERSION={php_version} bash .gitlab/build-profiler.sh "
            f"datadog-profiling/$(uname -m)-unknown-linux-gnu/lib/php/{php_api} {thread_safety}",
        ], os.path.join(logdir, "profiler.log")))

    for b in builds:
        b.start()
        print(f"  Started: {b.name} (log: {b.logfile})")

    # ── Wait ──────────────────────────────────────────────────────────────
    print(f"Waiting for {len(builds)} build(s)...")
    failed = []
    for b in builds:
        if b.wait():
            print(f"  Done: {b.name}")
        else:
            print(f"  FAILED: {b.name} (see {b.logfile})", file=sys.stderr)
            failed.append(b.name)

    if failed:
        sys.exit(f"Builds failed: {', '.join(failed)}. Logs in: {logdir}")

    # ── Assemble tarball ──────────────────────────────────────────────────
    print("Assembling tarball...")
    tmp_pkg = Path(tempfile.mkdtemp(prefix="slim-pkg-"))

    try:
        tracer_cache = f"dd-ci-{cp}-tracer-{php_version}-{thread_safety}"
        appsec_cache = f"dd-ci-{cp}-appsec-{php_version}"
        cpp_cache = f"dd-ci-{cp}-helper-cpp"
        rust_cache = f"dd-ci-{cp}-helper-rust"
        profiler_cache = f"dd-ci-{cp}-profiler-{php_version}"

        # Tracer
        trace_ext_dir = tmp_pkg / "dd-library-php" / "trace" / "ext" / php_api
        trace_ext_dir.mkdir(parents=True)
        subprocess.check_call([
            DOCKER_UPPER_CP, tracer_cache,
            "tmp/build_extension/modules/ddtrace.so",
            str(trace_ext_dir / f"ddtrace{ext_suffix}.so"),
        ])
        shutil.copytree(REPO_ROOT / "src", tmp_pkg / "dd-library-php" / "trace" / "src")
        (tmp_pkg / "dd-library-php" / "VERSION").write_text(version)

        # Appsec
        if build_appsec:
            appsec_ext_dir = tmp_pkg / "dd-library-php" / "appsec" / "ext" / php_api
            appsec_ext_dir.mkdir(parents=True)
            subprocess.check_call([
                DOCKER_UPPER_CP, appsec_cache,
                f"appsec_{arch}/ddappsec-{php_api}{ext_suffix}.so",
                str(appsec_ext_dir / f"ddappsec{ext_suffix}.so"),
            ])

            appsec_etc = tmp_pkg / "dd-library-php" / "appsec" / "etc"
            appsec_etc.mkdir(parents=True, exist_ok=True)
            shutil.copy2(
                REPO_ROOT / "appsec" / "recommended.json",
                appsec_etc / "recommended.json",
            )

            appsec_lib = tmp_pkg / "dd-library-php" / "appsec" / "lib"
            appsec_lib.mkdir(parents=True, exist_ok=True)

            subprocess.check_call([
                DOCKER_UPPER_CP, cpp_cache,
                f"appsec_{arch}/libddappsec-helper.so",
                str(appsec_lib / "libddappsec-helper.so"),
            ])
            subprocess.check_call([
                DOCKER_UPPER_CP, rust_cache,
                f"appsec_{arch}/libddappsec-helper-rust.so",
                str(appsec_lib / "libddappsec-helper-rust.so"),
            ])

        # Profiler
        if build_profiler:
            prof_ext_dir = tmp_pkg / "dd-library-php" / "profiling" / "ext" / php_api
            prof_ext_dir.mkdir(parents=True)
            subprocess.check_call([
                DOCKER_UPPER_CP, profiler_cache,
                f"datadog-profiling/{arch}-unknown-linux-gnu/lib/php/{php_api}/datadog-profiling{ext_suffix}.so",
                str(prof_ext_dir / f"datadog-profiling{ext_suffix}.so"),
            ])

        # Create tarball
        output_dir = Path(args.output_dir).resolve()
        output_dir.mkdir(parents=True, exist_ok=True)
        artifact_name = f"dd-library-php-{version}-{arch}-linux-gnu.tar.gz"
        artifact_path = output_dir / artifact_name

        with tarfile.open(artifact_path, "w:gz") as tar:
            tar.add(tmp_pkg / "dd-library-php", arcname="dd-library-php")

    finally:
        shutil.rmtree(tmp_pkg, ignore_errors=True)

    print()
    print(f"Artifact: {artifact_path}")
    print(f"Logs:     {logdir}")
    print()
    print("To use with system-tests:")
    print(f"  cp {artifact_path} {REPO_ROOT}/datadog-setup.php system-tests/binaries/")
    print(f"  cd system-tests")
    print(f"  WEBLOG_VARIANT=apache-mod-{php_version} ./build.sh php")
    print(f"  WEBLOG_VARIANT=apache-mod-{php_version} ./run.sh")


if __name__ == "__main__":
    main()
