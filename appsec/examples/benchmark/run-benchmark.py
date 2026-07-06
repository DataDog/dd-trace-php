#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# ///
"""Run k6 against the AppSec benchmark example and report throughput and CPU."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shlex
import shutil
import socket
import subprocess
import sys
import textwrap
import threading
import time
import signal
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from statistics import median
from typing import Any


PERF_EVENTS = ",".join(
    [
        "task-clock",
        "context-switches",
        "cpu-migrations",
        "cycles",
        "instructions",
    ]
)
OTEL_PROFILER_REPO_URL = "https://github.com/open-telemetry/opentelemetry-ebpf-profiler.git"
OTEL_PROFILER_IMAGE = "otel/opentelemetry-ebpf-profiler-dev:latest"


def default_toolchain_root() -> Path:
    return Path.home() / ".cache" / "dd-trace-php" / "appsec-benchmark" / "otel-ebpf-profiler"


def default_bin_dir() -> Path:
    return Path.home() / ".local" / "bin"


def default_profiler_bin() -> Path:
    system_profiler = Path("/usr/local/bin/ebpf-profiler")
    if system_profiler.exists():
        return system_profiler
    return default_bin_dir() / "ebpf-profiler"


def default_profile_receiver_bin() -> Path:
    return default_bin_dir() / "benchmark-profile-receiver"


@dataclass
class Runtime:
    root: Path
    out: Path
    project: str
    port: int
    compose_env: dict[str, str]
    compose_files: list[Path]


@dataclass(frozen=True)
class BoundarySnapshot:
    monotonic_ns: int
    container_usage_usec: int | None
    helper_cpu_ticks: int | None
    scheduler_by_role: dict[str, dict[str, int]]


@dataclass(frozen=True)
class MeasurementWindow:
    before: BoundarySnapshot
    after: BoundarySnapshot


def main() -> int:
    if len(sys.argv) > 1 and sys.argv[1] == "prepare-flamegraph-tools":
        return prepare_flamegraph_tools(parse_prepare_args(sys.argv[2:]))

    args = parse_args()
    cases = parse_cases(args.cases)
    root = Path(__file__).resolve().parent
    out = Path(args.out_dir or root / "results" / timestamp()).resolve()
    project = args.project_name or f"appsec-benchmark-{os.getpid()}"
    port = args.port or find_free_port()
    runtime = Runtime(
        root=root,
        out=out,
        project=project,
        port=port,
        compose_env={**os.environ, "HOST_PORT": str(port)},
        compose_files=[
            root / (
                "docker-compose.fpm.yml"
                if args.server == "fpm"
                else "docker-compose.yml"
            )
        ],
    )

    for command in ["docker", "curl"]:
        ensure_command(command)
    if "memory-profiler" in cases:
        validate_memory_profiler_args(args)
    ensure_docker_image("grafana/k6")
    if "memory-profiler" in cases:
        ensure_docker_image(args.memory_profiler_image)

    runtime.out.mkdir(parents=True, exist_ok=True)
    web_env = parse_web_env(args.web_env)
    if web_env:
        override = runtime.out / "docker-compose.override.yml"
        write_compose_override(override, web_env)
        runtime.compose_files.append(override)
    results: list[dict[str, Any]] = []

    try:
        compose(runtime, ["up", "-d", "--build", "web"])
        wait_ready(runtime, args.ready_timeout)
        container_id = capture(compose_cmd(runtime, ["ps", "-q", "web"]), cwd=runtime.root, env=runtime.compose_env)
        if not container_id:
            raise RuntimeError("could not resolve the web container id")
        if args.server != "fpm":
            bootstrap_appsec(container_id, args.helper_nofile)
        _, helper_pid = wait_for_helper_process(container_id, args.ready_timeout)
        helper_limits = require_helper_nofile(helper_pid, args.helper_nofile)
        write_text(runtime.out / "bootstrap-helper.limits", helper_limits)
        wait_for_appsec_ready(runtime, args, container_id)
        run_warmup(runtime, args)

        for mode in cases:
            if mode == "flamegraph":
                results.append(run_flamegraph_case(args, runtime, container_id))
            elif mode == "memory-profiler":
                results.append(
                    run_memory_profiler_case(args, runtime, container_id)
                )
            elif mode == "memory":
                results.append(run_memory_case(args, runtime, container_id))
            else:
                results.append(run_case(args, runtime, container_id, mode))

        write_text(runtime.out / "summary.json", json.dumps(results, indent=2, sort_keys=True))
        print_results(results)
        print(f"\nArtifacts: {runtime.out}")
        return 0
    finally:
        if not args.keep_running:
            compose(runtime, ["down", "--remove-orphans"], check=False)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run the local AppSec benchmark example.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--out-dir", help="Artifact directory.")
    parser.add_argument("--project-name", help="Docker Compose project name.")
    parser.add_argument("--port", type=int, help="Host port for the web container.")
    parser.add_argument("--keep-running", action="store_true", help="Leave the compose stack running.")
    parser.add_argument(
        "--server",
        choices=("frankenphp", "fpm"),
        default="frankenphp",
        help="Web runtime to benchmark. FPM uses 8 static non-ZTS workers with unlimited requests per child.",
    )
    parser.add_argument(
        "--cases",
        default="fixed1000,saturated",
        help=(
            "Comma-separated cases: "
            "fixed1000,saturated,flamegraph,memory,memory-profiler."
        ),
    )
    parser.add_argument("--fixed-rps", type=int, default=1000)
    parser.add_argument("--fixed-preallocated-vus", type=int, default=100)
    parser.add_argument("--fixed-max-vus", type=int, default=1000)
    parser.add_argument("--saturated-vus", type=int, default=128)
    parser.add_argument("--duration", type=int, default=25, help="k6 case duration in seconds.")
    parser.add_argument("--warmup-requests", type=int, default=500, help="Requests to send before measured cases.")
    parser.add_argument("--warmup-vus", type=int, default=16, help="Warmup virtual users.")
    parser.add_argument("--ready-timeout", type=float, default=30.0)
    parser.add_argument(
        "--helper-nofile",
        type=int,
        default=65536,
        help="Soft RLIMIT_NOFILE inherited by the sidecar/AppSec helper.",
    )
    parser.add_argument(
        "--instrumentation",
        choices=("none", "perf"),
        default="none",
        help="Optional instrumentation for fixed and saturated cases. OTel profiling is selected with --cases flamegraph.",
    )
    parser.add_argument("--perf-command", default="perf", help="Perf command, optionally with a prefix such as 'sudo -n perf'.")
    parser.add_argument("--perf-events", default=PERF_EVENTS)
    parser.add_argument("--perf-warmup-seconds", type=float, default=1.0)
    parser.add_argument("--perf-extra-seconds", type=int, default=5)
    parser.add_argument("--cpu-sample-interval", type=float, default=0.5)
    parser.add_argument("--web-env", action="append", default=[], metavar="KEY=VALUE", help="Extra web container environment variable.")
    parser.add_argument("--memory-rps", type=int, default=500)
    parser.add_argument("--memory-cycles", type=int, default=5)
    parser.add_argument("--memory-load-seconds", type=int, default=120)
    parser.add_argument("--memory-idle-seconds", type=int, default=20)
    parser.add_argument("--memory-initial-idle-seconds", type=int, default=20)
    parser.add_argument("--memory-sample-interval", type=float, default=5.0)
    parser.add_argument("--memory-preallocated-vus", type=int, default=100)
    parser.add_argument("--memory-max-vus", type=int, default=1000)
    parser.add_argument(
        "--memory-profiler-bin",
        help=(
            "Explicit path to the ebpf-memory-profiler executable. "
            "Required by --cases memory-profiler."
        ),
    )
    parser.add_argument("--memory-profiler-rps", type=int, default=40)
    parser.add_argument("--memory-profiler-duration", type=int, default=300)
    parser.add_argument("--memory-profiler-preallocated-vus", type=int, default=100)
    parser.add_argument("--memory-profiler-max-vus", type=int, default=1000)
    parser.add_argument("--memory-profiler-ring-buffer-mib", type=int, default=1024)
    parser.add_argument("--memory-profiler-stack-cache-entries", type=int, default=16384)
    parser.add_argument("--memory-profiler-settle-seconds", type=float, default=0.5)
    parser.add_argument("--memory-profiler-post-load-seconds", type=float, default=15.0)
    parser.add_argument("--memory-profiler-stop-timeout", type=int, default=60)
    parser.add_argument(
        "--memory-profiler-image",
        default="ubuntu:24.04",
        help="Container image used to execute the statically linked memory profiler.",
    )
    parser.add_argument("--flamegraph-rps", type=int, default=500)
    parser.add_argument("--flamegraph-duration", type=int, default=60)
    parser.add_argument("--flamegraph-preallocated-vus", type=int, default=100)
    parser.add_argument("--flamegraph-max-vus", type=int, default=1000)
    parser.add_argument("--flamegraph-autodiscover-rps", action="store_true", help="Probe and select a near-saturation RPS before flamegraph capture.")
    parser.add_argument("--flamegraph-autodiscover-min-rps", type=int, default=1000)
    parser.add_argument("--flamegraph-autodiscover-max-rps", type=int, default=3000)
    parser.add_argument("--flamegraph-autodiscover-hard-max-rps", type=int, default=50000)
    parser.add_argument("--flamegraph-autodiscover-step-rps", type=int, default=100)
    parser.add_argument("--flamegraph-autodiscover-duration", type=int, default=20)
    parser.add_argument("--flamegraph-autodiscover-max-dropped-rate", type=float, default=0.001)
    parser.add_argument("--flamegraph-autodiscover-max-p95-ms", type=float, default=300.0)
    parser.add_argument("--flamegraph-autodiscover-preallocated-vus", type=int, default=500)
    parser.add_argument("--flamegraph-autodiscover-max-vus", type=int, default=2000)
    parser.add_argument("--flamegraph-include", default="web,helper", help="Comma-separated roots to include: web,helper,other.")
    parser.add_argument("--flamegraph-profiler-command", default=f"sudo -n {default_profiler_bin()}")
    parser.add_argument("--flamegraph-profile-receiver-command", default=str(default_profile_receiver_bin()))
    parser.add_argument("--flamegraph-profiler-stop-command", help="Optional command run after capture to stop a privileged profiler.")
    parser.add_argument("--flamegraph-samples-per-second", type=int, default=199)
    parser.add_argument("--flamegraph-reporter-interval", type=float, default=2.0)
    parser.add_argument("--flamegraph-monitor-interval", type=float, default=2.0)
    parser.add_argument("--flamegraph-collection-agent", default="127.0.0.1:11001")
    parser.add_argument("--flamegraph-render-command", help="Command used to render folded stacks to SVG, such as flamegraph.pl.")
    return parser.parse_args()


def parse_prepare_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Prepare the durable OTel eBPF profiler toolchain used by flamegraph mode.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    parser.add_argument("--otel-profiler-root", default=str(default_toolchain_root()), help="Durable checkout/build directory.")
    parser.add_argument("--otel-profiler-repo-url", default=OTEL_PROFILER_REPO_URL)
    parser.add_argument("--otel-profiler-ref", help="Optional git ref to check out before building.")
    parser.add_argument("--bin-dir", default=str(default_bin_dir()), help="Directory for installed user binaries.")
    parser.add_argument("--profiler-bin", help="Installed ebpf-profiler path. Defaults to BIN_DIR/ebpf-profiler.")
    parser.add_argument(
        "--profile-receiver-bin",
        help="Installed benchmark profile receiver path. Defaults to BIN_DIR/benchmark-profile-receiver.",
    )
    parser.add_argument("--receiver-image", default=OTEL_PROFILER_IMAGE, help="Dev image built from the profiler Dockerfile.")
    parser.add_argument("--force-rebuild", action="store_true", help="Rebuild binaries even if cached outputs exist.")
    return parser.parse_args(argv)


def parse_cases(raw: str) -> list[str]:
    cases = [case.strip() for case in raw.split(",") if case.strip()]
    valid = {
        "fixed1000",
        "saturated",
        "flamegraph",
        "memory",
        "memory-profiler",
    }
    invalid = [case for case in cases if case not in valid]
    if invalid:
        raise SystemExit(f"invalid --cases value(s): {', '.join(invalid)}")
    return cases


def prepare_flamegraph_tools(args: argparse.Namespace) -> int:
    ensure_command("git")
    ensure_command("docker")
    ensure_command("make")

    root = Path(args.otel_profiler_root).expanduser().resolve()
    bin_dir = Path(args.bin_dir).expanduser().resolve()
    profiler_bin = Path(args.profiler_bin).expanduser().resolve() if args.profiler_bin else bin_dir / "ebpf-profiler"
    receiver_bin = (
        Path(args.profile_receiver_bin).expanduser().resolve()
        if args.profile_receiver_bin
        else bin_dir / "benchmark-profile-receiver"
    )

    ensure_profiler_checkout(root, args.otel_profiler_repo_url, args.otel_profiler_ref)
    ensure_profiler_dev_image(root, args.receiver_image, args.force_rebuild)
    build_profiler_binary(root, args.receiver_image, args.force_rebuild)
    receiver_source = write_cached_profile_receiver_source(root)
    receiver_build = build_profile_receiver_binary(root, receiver_source, args.receiver_image, args.force_rebuild)
    install_executable(root / "ebpf-profiler", profiler_bin)
    install_executable(receiver_build, receiver_bin)

    print("Prepared OTel eBPF profiler toolchain:")
    print(f"  checkout: {root}")
    print(f"  profiler: {profiler_bin}")
    print(f"  receiver: {receiver_bin}")
    print(f"  image:    {args.receiver_image}")
    print()
    print("Run flamegraph mode with defaults, or override with:")
    print(f"  --flamegraph-profiler-command 'sudo -n {profiler_bin}'")
    print(f"  --flamegraph-profile-receiver-command {receiver_bin}")
    return 0


def ensure_profiler_checkout(root: Path, repo_url: str, ref: str | None) -> None:
    if not root.exists():
        root.parent.mkdir(parents=True, exist_ok=True)
        run(["git", "clone", repo_url, str(root)])
    elif not (root / ".git").exists():
        raise SystemExit(f"OTel profiler root exists but is not a git checkout: {root}")

    if ref:
        run(["git", "-C", str(root), "fetch", "--tags", "origin"])
        run(["git", "-C", str(root), "checkout", ref])


def ensure_profiler_dev_image(root: Path, image: str, force_rebuild: bool) -> None:
    image_exists = run(
        ["docker", "image", "inspect", image],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    ).returncode == 0
    if force_rebuild or not image_exists:
        run(["make", "-C", str(root), "docker-image"])


def build_profiler_binary(root: Path, image: str, force_rebuild: bool) -> None:
    output = root / "ebpf-profiler"
    if output.exists() and not force_rebuild:
        return
    run_in_profiler_dev_image(root, image, "make ebpf-profiler")


def write_cached_profile_receiver_source(root: Path) -> Path:
    receiver_source = root / ".benchmark-tools" / "profile-receiver" / "main.go"
    write_text(receiver_source, profile_receiver_source())
    return receiver_source


def build_profile_receiver_binary(root: Path, receiver_source: Path, image: str, force_rebuild: bool) -> Path:
    output = root / ".benchmark-tools" / "bin" / "benchmark-profile-receiver"
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists() and not force_rebuild:
        return output

    relative_source = receiver_source.relative_to(root)
    relative_output = output.relative_to(root)
    run_in_profiler_dev_image(
        root,
        image,
        f"go build -o {shlex.quote('/agent/' + relative_output.as_posix())} "
        f"{shlex.quote('/agent/' + relative_source.as_posix())}",
    )
    output.chmod(0o755)
    return output


def run_in_profiler_dev_image(root: Path, image: str, command: str) -> None:
    run(
        [
            "docker",
            "run",
            "--rm",
            "--user",
            f"{os.getuid()}:{os.getgid()}",
            "-v",
            f"{root}:/agent",
            "-w",
            "/agent",
            image,
            command,
        ]
    )


def install_executable(source: Path, destination: Path) -> None:
    if not source.exists():
        raise SystemExit(f"expected built executable not found: {source}")
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)
    destination.chmod(0o755)


def parse_web_env(items: list[str]) -> dict[str, str]:
    env: dict[str, str] = {}
    for item in items:
        if "=" not in item:
            raise SystemExit(f"--web-env must be KEY=VALUE, got: {item}")
        key, value = item.split("=", 1)
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key):
            raise SystemExit(f"invalid --web-env key: {key}")
        env[key] = value
    return env


def write_compose_override(path: Path, env: dict[str, str]) -> None:
    lines = ["services:", "  web:", "    environment:"]
    for key, value in sorted(env.items()):
        lines.append(f"      {key}: {json.dumps(value)}")
    write_text(path, "\n".join(lines) + "\n")


def bootstrap_appsec(container_id: str, helper_nofile: int) -> None:
    if helper_nofile < 4096:
        raise ValueError("--helper-nofile must be at least 4096")
    run(
        [
            "docker",
            "exec",
            container_id,
            "sh",
            "-c",
            'ulimit -Sn "$1" && exec php --ri ddappsec',
            "appsec-benchmark-bootstrap",
            str(helper_nofile),
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def wait_for_appsec_ready(
    runtime: Runtime,
    args: argparse.Namespace,
    container_id: str,
) -> None:
    result = appsec_readiness_check(
        runtime,
        args,
        container_id,
        timeout=args.ready_timeout,
    )
    if result["ok"]:
        return
    raise RuntimeError(
        f"AppSec WAF did not complete readiness validation within "
        f"{args.ready_timeout:.1f}s ({format_appsec_readiness_failure(result)})"
    )


def appsec_ready_response(
    runtime: Runtime,
    *,
    include_worker_pid: bool = False,
) -> subprocess.CompletedProcess[str]:
    command = ["curl", "--silent"]
    if include_worker_pid:
        command.extend(
            [
                "--output",
                "-",
                "--write-out",
                "\n%{http_code}",
                (
                    f"http://127.0.0.1:{runtime.port}/__appsec_ready"
                    "?benchmark_worker_pid=1"
                ),
            ]
        )
    else:
        command.extend(
            [
                "--output",
                "/dev/null",
                "--write-out",
                "%{http_code}",
                f"http://127.0.0.1:{runtime.port}/__appsec_ready",
            ]
        )
    return subprocess.run(
        command,
        text=True,
        stderr=subprocess.DEVNULL,
        check=False,
        stdout=subprocess.PIPE,
    )


def appsec_readiness_check(
    runtime: Runtime,
    args: argparse.Namespace,
    container_id: str,
    *,
    timeout: float | None = None,
) -> dict[str, Any]:
    """Validate a real WAF request, covering every persistent FPM child."""
    if args.server != "fpm":
        response = appsec_ready_response(runtime)
        status = response.stdout or None
        return {
            "ok": response.returncode == 0 and status == "204",
            "last_http_status": status,
            "curl_returncode": response.returncode,
        }

    expected_pids = fpm_worker_namespace_pids(container_id)
    deadline = time.monotonic() + (
        args.ready_timeout if timeout is None else timeout
    )
    observations: dict[str, list[str]] = {
        pid: [] for pid in sorted(expected_pids, key=int)
    }
    unknown_observations: list[str] = []
    ready_pids: set[str] = set()
    last_returncode = 0

    while ready_pids != expected_pids:
        response = appsec_ready_response(runtime, include_worker_pid=True)
        last_returncode = response.returncode
        fields = response.stdout.splitlines()
        status = (
            fields[-1].strip()
            if fields
            else f"curl-exit-{response.returncode}"
        )
        worker_pid = fields[0].strip() if len(fields) == 2 else ""
        if worker_pid in expected_pids:
            observations[worker_pid].append(status)
            if status == "200" and response.returncode == 0:
                ready_pids.add(worker_pid)
        else:
            unknown_observations.append(
                f"{status}:{worker_pid or 'missing-pid'}"
            )
        if ready_pids == expected_pids or time.monotonic() >= deadline:
            break
        time.sleep(0.05)

    missing = sorted(expected_pids - ready_pids, key=int)
    return {
        "ok": not missing,
        "expected_worker_pids": sorted(expected_pids, key=int),
        "ready_worker_pids": sorted(ready_pids, key=int),
        "missing_worker_pids": missing,
        "observations_by_worker": observations,
        "unknown_observations": unknown_observations,
        "curl_returncode": last_returncode,
    }


def fpm_worker_namespace_pids(container_id: str) -> set[str]:
    result = run(
        ["docker", "top", container_id, "-eo", "pid,comm,args"],
        check=False,
        stderr=subprocess.PIPE,
        stdout=subprocess.PIPE,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"cannot list FPM worker processes: {result.stderr.strip()}"
        )
    host_pids: list[str] = []
    for line in result.stdout.splitlines()[1:]:
        fields = line.split(maxsplit=2)
        if (
            len(fields) == 3
            and fields[0].isdigit()
            and "php-fpm: pool www" in fields[2]
        ):
            host_pids.append(fields[0])
    if not host_pids:
        raise RuntimeError("no persistent PHP-FPM worker processes found")
    return {process_namespace_pid(pid) for pid in host_pids}


def format_appsec_readiness_failure(result: dict[str, Any]) -> str:
    if "missing_worker_pids" in result:
        observations = result.get("observations_by_worker", {})
        def summarize(pid: str) -> str:
            statuses = observations.get(pid) or []
            if not statuses:
                return "not observed"
            recent = ",".join(statuses[-8:])
            return f"{len(statuses)} observation(s), latest [{recent}]"

        details = ", ".join(
            f"pid {pid}: {summarize(pid)}"
            for pid in result["missing_worker_pids"]
        )
        return f"FPM workers not ready: {details}"
    status = result.get("last_http_status") or (
        f"curl exit {result.get('curl_returncode')}"
    )
    return f"last status: {status}"


def run_warmup(runtime: Runtime, args: argparse.Namespace) -> None:
    if args.warmup_requests <= 0:
        return

    script = textwrap.dedent(
        f"""
        import http from "k6/http";
        export const options = {{ vus: {args.warmup_vus}, iterations: {args.warmup_requests} }};
        export default function () {{ http.get("http://127.0.0.1:{runtime.port}/"); }}
        """
    ).lstrip()
    run(
        [
            "docker",
            "run",
            "--rm",
            "--network",
            "host",
            "-i",
            "grafana/k6",
            "run",
            "--quiet",
            "-",
        ],
        stdin=script,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def run_case(
    args: argparse.Namespace,
    runtime: Runtime,
    container_id: str,
    mode: str,
) -> dict[str, Any]:
    case_dir = runtime.out / mode
    case_dir.mkdir(parents=True, exist_ok=True)

    ready_before = appsec_ready_response(runtime)
    validation = {
        "appsec_ready_http_status_before_run": ready_before.stdout or None,
        "appsec_request_exec_succeeded_before_run": (
            ready_before.returncode == 0 and ready_before.stdout == "204"
        ),
        "appsec_ready_http_status_after_run": None,
        "appsec_request_exec_succeeded_after_run": None,
        "helper_shutdown_during_run": None,
    }
    write_text(
        case_dir / "appsec-validation.json",
        json.dumps(validation, indent=2, sort_keys=True) + "\n",
    )
    if ready_before.returncode != 0 or ready_before.stdout != "204":
        status = ready_before.stdout or f"curl exit {ready_before.returncode}"
        raise RuntimeError(f"AppSec WAF failed pre-run validation (status: {status})")

    inspect = json.loads(capture(["docker", "inspect", container_id]))
    write_text(case_dir / "docker-inspect.json", json.dumps(inspect, indent=2))
    container_pid = str(inspect[0]["State"]["Pid"])

    docker_top, helper_pid = wait_for_helper_process(container_id, args.ready_timeout)
    write_text(case_dir / "docker-top.txt", docker_top + "\n")
    write_text(case_dir / "helper.pid", helper_pid + "\n")
    write_text(case_dir / "helper.limits", require_helper_nofile(helper_pid, args.helper_nofile))
    write_proc_maps(helper_pid, case_dir)

    cpu_stat = container_cgroup_cpu_stat(container_pid)
    if cpu_stat is not None:
        write_text(case_dir / "cgroup-cpu-stat-path.txt", str(cpu_stat) + "\n")
    sampler = CpuSampler(cpu_stat, container_id, case_dir / "cpu-samples.csv", args.cpu_sample_interval)
    helper_sampler = ProcessCpuSampler(helper_pid, case_dir / "helper-cpu-samples.csv", args.cpu_sample_interval)

    perf_handles: list[tuple[subprocess.Popen[str] | None, Any]] = []
    if args.instrumentation == "perf":
        perf_handles = [
            start_perf(args, helper_pid, case_dir, "perf-stat.csv"),
            start_perf(args, container_pid, case_dir, "frankenphp-perf-stat.csv"),
        ]
    try:
        if perf_handles:
            time.sleep(args.perf_warmup_seconds)
        measurement_before = capture_boundary_snapshot(
            cpu_stat, container_pid, helper_pid, scheduler_first=True
        )
        sampler.start()
        helper_sampler.start()
        run_k6(case_dir, k6_script(mode, runtime.port, args))
        measurement_after = capture_boundary_snapshot(
            cpu_stat, container_pid, helper_pid, scheduler_first=False
        )
        measurement_window = MeasurementWindow(measurement_before, measurement_after)
        sampler.stop()
        helper_sampler.stop()
        for perf_process, perf_file in perf_handles:
            wait_perf(perf_process, perf_file, args.duration + args.perf_extra_seconds + 10)
        write_text(case_dir / "docker.log", capture(["docker", "logs", container_id]) + "\n")
        helper_log = capture(["docker", "exec", container_id, "cat", "/tmp/ddappsec.log"]) + "\n"
        write_text(case_dir / "appsec-helper.log", helper_log)
        ready_response = appsec_ready_response(runtime)
        helper_shutdown = "AppSec helper shutdown initiated" in helper_log
        validation["appsec_ready_http_status_after_run"] = ready_response.stdout or None
        validation["appsec_request_exec_succeeded_after_run"] = (
            ready_response.returncode == 0 and ready_response.stdout == "204"
        )
        validation["helper_shutdown_during_run"] = helper_shutdown
        write_text(
            case_dir / "appsec-validation.json",
            json.dumps(validation, indent=2, sort_keys=True) + "\n",
        )
        if ready_response.returncode != 0 or ready_response.stdout != "204":
            status = ready_response.stdout or f"curl exit {ready_response.returncode}"
            raise RuntimeError(f"AppSec WAF failed post-run validation (status: {status})")
        if helper_shutdown:
            raise RuntimeError("AppSec helper shut down during the benchmark case")
        return summarize_case(case_dir, sampler, helper_sampler, measurement_window, args)
    finally:
        sampler.stop()
        helper_sampler.stop()
        for perf_process, perf_file in perf_handles:
            stop_perf(perf_process, perf_file)


def run_memory_case(
    args: argparse.Namespace,
    runtime: Runtime,
    container_id: str,
) -> dict[str, Any]:
    validate_memory_args(args)
    case_dir = runtime.out / "memory"
    case_dir.mkdir(parents=True, exist_ok=True)

    ready_before = appsec_readiness_check(
        runtime,
        args,
        container_id,
        timeout=args.ready_timeout,
    )
    validation = {
        "appsec_readiness_before_run": ready_before,
        "appsec_request_exec_succeeded_before_run": ready_before["ok"],
        "appsec_readiness_after_run": None,
        "appsec_request_exec_succeeded_after_run": None,
        "helper_shutdown_during_run": None,
        "open_file_limit_error_during_run": None,
    }
    write_text(
        case_dir / "appsec-validation.json",
        json.dumps(validation, indent=2, sort_keys=True) + "\n",
    )
    if not ready_before["ok"]:
        raise RuntimeError(
            "AppSec WAF failed pre-run validation "
            f"({format_appsec_readiness_failure(ready_before)})"
        )

    inspect = json.loads(capture(["docker", "inspect", container_id]))
    write_text(case_dir / "docker-inspect.json", json.dumps(inspect, indent=2))
    container_pid = str(inspect[0]["State"]["Pid"])
    docker_top, helper_pid = wait_for_helper_process(container_id, args.ready_timeout)
    write_text(case_dir / "docker-top.txt", docker_top + "\n")
    write_text(case_dir / "helper.pid", helper_pid + "\n")
    write_text(case_dir / "helper.limits", require_helper_nofile(helper_pid, args.helper_nofile))

    cgroup_dir = container_cgroup_dir(container_pid)
    if cgroup_dir is None:
        raise RuntimeError(f"could not resolve cgroup v2 directory for container pid {container_pid}")
    if not cgroup_dir.joinpath("memory.current").exists():
        raise RuntimeError(f"memory.current is missing from container cgroup {cgroup_dir}")
    write_text(case_dir / "cgroup-path.txt", str(cgroup_dir) + "\n")
    pids = {"web": container_pid, "helper": helper_pid}
    process_reader = (
        AggregateProcessMemoryReader(container_id, args.helper_nofile)
        if args.server == "fpm"
        else ProcessMemoryReader(container_id, pids, args.helper_nofile)
    )
    write_text(
        case_dir / "capture-config.json",
        json.dumps(
            {
                "cycles": args.memory_cycles,
                "helper_nofile": args.helper_nofile,
                "idle_seconds_per_cycle": args.memory_idle_seconds,
                "initial_idle_seconds": args.memory_initial_idle_seconds,
                "load_seconds_per_cycle": args.memory_load_seconds,
                "max_vus": args.memory_max_vus,
                "preallocated_vus": args.memory_preallocated_vus,
                "process_reader": process_reader.mode,
                "rps": args.memory_rps,
                "sample_interval_seconds": args.memory_sample_interval,
                "server": args.server,
                "fpm": (
                    {
                        "pm": "static",
                        "pm.max_children": 8,
                        "pm.max_requests": 0,
                    }
                    if args.server == "fpm"
                    else None
                ),
            },
            indent=2,
            sort_keys=True,
        )
        + "\n",
    )

    sampler = MemorySampler(
        cgroup_dir,
        process_reader,
        case_dir / "memory-samples.csv",
        args.memory_sample_interval,
    )
    phase_snapshots: list[dict[str, Any]] = []
    cumulative_requests = 0.0
    cumulative_dropped = 0.0
    cumulative_failures = 0.0
    cycle_summaries: list[dict[str, Any]] = []

    def snapshot(label: str, cycle: int) -> dict[str, Any]:
        value = capture_memory_snapshot(cgroup_dir, process_reader)
        value.update(
            {
                "label": label,
                "cycle": cycle,
                "cumulative_requests": cumulative_requests,
            }
        )
        phase_snapshots.append(value)
        write_text(
            case_dir / "snapshots" / f"{len(phase_snapshots):02d}-{label}.json",
            json.dumps(value, indent=2, sort_keys=True) + "\n",
        )
        return value

    sampler.set_phase("initial_idle", 0)
    sampler.start()
    try:
        snapshot("start", 0)
        wait_memory_phase("initial idle", args.memory_initial_idle_seconds)
        snapshot("baseline_after_idle", 0)
        process_reader.write_full_snapshot(case_dir / "baseline")

        for cycle in range(1, args.memory_cycles + 1):
            cycle_dir = case_dir / f"cycle-{cycle:02d}-load"
            sampler.set_phase("load", cycle)
            print(
                f"Memory cycle {cycle}/{args.memory_cycles}: "
                f"{args.memory_rps} RPS for {args.memory_load_seconds}s",
                flush=True,
            )
            run_k6(
                cycle_dir,
                constant_arrival_k6_script(
                    runtime.port,
                    args.memory_rps,
                    args.memory_load_seconds,
                    args.memory_preallocated_vus,
                    args.memory_max_vus,
                ),
            )
            summary_path = cycle_dir / "k6-summary.json"
            if not summary_path.exists():
                raise RuntimeError(f"k6 did not write {summary_path}")
            k6_summary = json.loads(summary_path.read_text())
            requests = metric_value(k6_summary, "http_reqs", "count") or 0.0
            dropped = metric_value(k6_summary, "dropped_iterations", "count") or 0.0
            failed_rate = metric_value(k6_summary, "http_req_failed", "value") or 0.0
            cumulative_requests += requests
            cumulative_dropped += dropped
            cumulative_failures += requests * failed_rate
            cycle_summary = {
                "cycle": cycle,
                "requests": requests,
                "dropped_iterations": dropped,
                "failed_rate": failed_rate,
                "duration_avg_ms": metric_value(k6_summary, "http_req_duration", "avg"),
                "duration_p95_ms": metric_value(k6_summary, "http_req_duration", "p(95)"),
                "duration_p99_ms": metric_value(k6_summary, "http_req_duration", "p(99)"),
            }
            cycle_summaries.append(cycle_summary)
            ready_after_load = appsec_readiness_check(
                runtime,
                args,
                container_id,
                timeout=args.ready_timeout,
            )
            if not ready_after_load["ok"]:
                raise RuntimeError(
                    f"AppSec WAF failed validation after memory load cycle {cycle} "
                    f"({format_appsec_readiness_failure(ready_after_load)})"
                )
            snapshot(f"cycle_{cycle:02d}_after_load", cycle)

            sampler.set_phase("idle", cycle)
            wait_memory_phase(
                f"cycle {cycle}/{args.memory_cycles} idle drain",
                args.memory_idle_seconds,
            )
            ready = appsec_readiness_check(
                runtime,
                args,
                container_id,
                timeout=args.ready_timeout,
            )
            if not ready["ok"]:
                raise RuntimeError(
                    f"AppSec WAF failed validation after memory cycle {cycle} "
                    f"({format_appsec_readiness_failure(ready)})"
                )
            snapshot(f"cycle_{cycle:02d}_after_idle", cycle)
    finally:
        sampler.stop()

    final_snapshot = snapshot("final", args.memory_cycles)
    process_reader.write_full_snapshot(case_dir / "final")
    memory_analysis = analyze_memory_profile(phase_snapshots, sampler.samples)
    memory_analysis["process_reader"] = {
        "mode": process_reader.mode,
        "helper_generations": process_reader.helper_generation,
        "helper_transitions": process_reader.transitions,
    }
    write_text(
        case_dir / "memory-analysis.json",
        json.dumps(memory_analysis, indent=2, sort_keys=True) + "\n",
    )
    write_text(
        case_dir / "phase-snapshots.json",
        json.dumps(phase_snapshots, indent=2, sort_keys=True) + "\n",
    )
    write_text(
        case_dir / "cycle-summaries.json",
        json.dumps(cycle_summaries, indent=2, sort_keys=True) + "\n",
    )

    helper_log = capture(["docker", "exec", container_id, "cat", "/tmp/ddappsec.log"]) + "\n"
    write_text(case_dir / "appsec-helper.log", helper_log)
    write_text(case_dir / "docker.log", capture(["docker", "logs", container_id]) + "\n")
    ready_after = appsec_readiness_check(
        runtime,
        args,
        container_id,
        timeout=args.ready_timeout,
    )
    helper_shutdown = "AppSec helper shutdown initiated" in helper_log
    open_file_limit_error = bool(re.search(r"too many open files|EMFILE", helper_log, re.IGNORECASE))
    validation["appsec_readiness_after_run"] = ready_after
    validation["appsec_request_exec_succeeded_after_run"] = ready_after["ok"]
    validation["helper_shutdown_during_run"] = helper_shutdown
    validation["open_file_limit_error_during_run"] = open_file_limit_error
    write_text(
        case_dir / "appsec-validation.json",
        json.dumps(validation, indent=2, sort_keys=True) + "\n",
    )
    if not ready_after["ok"]:
        raise RuntimeError(
            "AppSec WAF failed post-run validation "
            f"({format_appsec_readiness_failure(ready_after)})"
        )
    if helper_shutdown:
        raise RuntimeError("AppSec helper shut down during the memory case")
    if open_file_limit_error:
        raise RuntimeError("AppSec helper encountered an open-file limit error during the memory case")
    if cumulative_failures > 0:
        raise RuntimeError(f"HTTP failures occurred during the memory case: {cumulative_failures}")
    if cumulative_dropped > 0:
        raise RuntimeError(f"k6 dropped iterations during the memory case: {cumulative_dropped}")

    active_seconds = args.memory_cycles * args.memory_load_seconds
    result = {
        "case": "memory",
        "instrumentation": "procfs+cgroup-v2",
        "load_model": "constant-arrival-rate-with-idle-drain",
        "load": (
            f"{args.memory_rps}rps "
            f"{args.memory_cycles}x{args.memory_load_seconds}s"
        ),
        "target_rps": args.memory_rps,
        "vus": None,
        "active_rps": cumulative_requests / active_seconds if active_seconds > 0 else None,
        "load_window_rps": cumulative_requests / active_seconds if active_seconds > 0 else None,
        "completion_rps": None,
        "http_reqs_count": cumulative_requests,
        "http_req_failed_rate": (
            cumulative_failures / cumulative_requests if cumulative_requests > 0 else None
        ),
        "dropped_iterations": cumulative_dropped,
        "dropped_iterations_rate": (
            cumulative_dropped / (cumulative_requests + cumulative_dropped)
            if cumulative_requests + cumulative_dropped > 0
            else None
        ),
        "http_req_duration_avg_ms": None,
        "http_req_duration_med_ms": None,
        "http_req_duration_p95_ms": None,
        "http_req_duration_p99_ms": None,
        "http_req_duration_max_ms": None,
        "helper_task_clock_ms": None,
        "helper_perf_elapsed_s": None,
        "helper_cpu_pct": None,
        "helper_cpu_source": None,
        "helper_cpu_ms_per_req": None,
        "container_cpu_avg_pct": None,
        "container_cpu_source": None,
        "container_cpu_ms_per_req": None,
        "measurement_window_s": (
            final_snapshot["monotonic_ns"] - phase_snapshots[0]["monotonic_ns"]
        )
        / 1_000_000_000,
        "scheduler": {},
        "memory": memory_analysis,
    }
    write_text(case_dir / "summary.json", json.dumps(result, indent=2, sort_keys=True) + "\n")
    return result


def validate_memory_args(args: argparse.Namespace) -> None:
    positive = {
        "--memory-rps": args.memory_rps,
        "--memory-cycles": args.memory_cycles,
        "--memory-load-seconds": args.memory_load_seconds,
        "--memory-sample-interval": args.memory_sample_interval,
    }
    for name, value in positive.items():
        if value <= 0:
            raise SystemExit(f"{name} must be positive")
    nonnegative = {
        "--memory-idle-seconds": args.memory_idle_seconds,
        "--memory-initial-idle-seconds": args.memory_initial_idle_seconds,
    }
    for name, value in nonnegative.items():
        if value < 0:
            raise SystemExit(f"{name} must be non-negative")


def wait_memory_phase(description: str, duration: float) -> None:
    print(f"Memory phase: {description} ({duration:.0f}s)", flush=True)
    if duration > 0:
        time.sleep(duration)


def run_memory_profiler_case(
    args: argparse.Namespace,
    runtime: Runtime,
    container_id: str,
) -> dict[str, Any]:
    validate_memory_profiler_args(args)
    case_dir = runtime.out / "memory-profiler"
    case_dir.mkdir(parents=True, exist_ok=True)

    ready_before = appsec_readiness_check(
        runtime,
        args,
        container_id,
        timeout=args.ready_timeout,
    )
    if not ready_before["ok"]:
        raise RuntimeError(
            "AppSec WAF failed pre-run validation "
            f"({format_appsec_readiness_failure(ready_before)})"
        )

    inspect = json.loads(capture(["docker", "inspect", container_id]))
    write_text(case_dir / "docker-inspect.json", json.dumps(inspect, indent=2))
    container_pid = str(inspect[0]["State"]["Pid"])
    docker_top, helper_pid = wait_for_single_helper_process(
        container_id,
        args.ready_timeout,
    )
    helper_namespace_pid = process_namespace_pid(helper_pid)
    if helper_namespace_pid == "1":
        raise RuntimeError(
            "refusing to profile helper namespace PID 1 because the profiler "
            "briefly stops its target while attaching"
        )

    write_text(case_dir / "docker-top.before.txt", docker_top + "\n")
    write_text(case_dir / "helper.pid", helper_pid + "\n")
    write_text(
        case_dir / "helper.namespace-pid",
        helper_namespace_pid + "\n",
    )
    write_text(
        case_dir / "helper.limits.before",
        require_helper_nofile(helper_pid, args.helper_nofile),
    )
    write_proc_maps(helper_pid, case_dir)

    profiler_bin = Path(args.memory_profiler_bin).resolve()
    profiler_name = f"{runtime.project}-memory-profiler"
    profile_path = case_dir / "outstanding-allocations.jsonl"
    profiler_config = {
        "binary_path": str(profiler_bin),
        "binary_sha256": sha256_file(profiler_bin),
        "container_image": args.memory_profiler_image,
        "container_name": profiler_name,
        "helper_host_pid": int(helper_pid),
        "helper_namespace_pid": int(helper_namespace_pid),
        "max_vus": args.memory_profiler_max_vus,
        "post_load_seconds": args.memory_profiler_post_load_seconds,
        "preallocated_vus": args.memory_profiler_preallocated_vus,
        "readiness_marker": "Supports LPM trie",
        "ring_buffer_mib": args.memory_profiler_ring_buffer_mib,
        "rps": args.memory_profiler_rps,
        "settle_seconds": args.memory_profiler_settle_seconds,
        "stack_cache_entries": args.memory_profiler_stack_cache_entries,
        "stop_timeout_seconds": args.memory_profiler_stop_timeout,
        "target_container_id": container_id,
        "load_seconds": args.memory_profiler_duration,
    }
    write_text(
        case_dir / "capture-config.json",
        json.dumps(profiler_config, indent=2, sort_keys=True) + "\n",
    )

    cpu_stat = container_cgroup_cpu_stat(container_pid)
    if cpu_stat is not None:
        write_text(case_dir / "cgroup-cpu-stat-path.txt", str(cpu_stat) + "\n")
    sampler = CpuSampler(
        cpu_stat,
        container_id,
        case_dir / "cpu-samples.csv",
        args.cpu_sample_interval,
    )
    helper_sampler = ProcessCpuSampler(
        helper_pid,
        case_dir / "helper-cpu-samples.csv",
        args.cpu_sample_interval,
    )

    profiler_started = False
    measurement_window: MeasurementWindow | None = None
    ready_after: dict[str, Any] | None = None
    helper_stable = False
    try:
        start_memory_profiler_container(
            args,
            container_id,
            helper_namespace_pid,
            profiler_bin,
            profiler_name,
            case_dir,
        )
        profiler_started = True
        wait_for_memory_profiler(
            profiler_name,
            case_dir / "profiler.log",
            args.ready_timeout,
        )
        if args.memory_profiler_settle_seconds > 0:
            time.sleep(args.memory_profiler_settle_seconds)

        measurement_before = capture_boundary_snapshot(
            cpu_stat,
            container_pid,
            helper_pid,
            scheduler_first=True,
        )
        sampler.start()
        helper_sampler.start()
        run_k6(
            case_dir,
            constant_arrival_k6_script(
                runtime.port,
                args.memory_profiler_rps,
                args.memory_profiler_duration,
                args.memory_profiler_preallocated_vus,
                args.memory_profiler_max_vus,
            ),
        )
        measurement_after = capture_boundary_snapshot(
            cpu_stat,
            container_pid,
            helper_pid,
            scheduler_first=False,
        )
        measurement_window = MeasurementWindow(
            measurement_before,
            measurement_after,
        )
        sampler.stop()
        helper_sampler.stop()

        ready_after = appsec_readiness_check(
            runtime,
            args,
            container_id,
            timeout=args.ready_timeout,
        )
        docker_top_after, helper_pid_after = wait_for_single_helper_process(
            container_id,
            args.ready_timeout,
        )
        helper_stable = helper_pid_after == helper_pid
        write_text(case_dir / "docker-top.after.txt", docker_top_after + "\n")
        if args.memory_profiler_post_load_seconds > 0:
            wait_memory_phase(
                "memory profiler post-load drain",
                args.memory_profiler_post_load_seconds,
            )
    finally:
        sampler.stop()
        helper_sampler.stop()
        if profiler_started:
            stop_memory_profiler_container(
                profiler_name,
                profile_path,
                case_dir,
                args.memory_profiler_stop_timeout,
            )

    if measurement_window is None:
        raise RuntimeError("memory profiler load did not establish a measurement window")

    profile_analysis = analyze_memory_profiler_output(
        profile_path,
        helper_namespace_pid,
    )
    write_text(
        case_dir / "profile-analysis.json",
        json.dumps(profile_analysis, indent=2, sort_keys=True) + "\n",
    )

    docker_log = capture(["docker", "logs", container_id]) + "\n"
    helper_log = (
        capture(["docker", "exec", container_id, "cat", "/tmp/ddappsec.log"])
        + "\n"
    )
    write_text(case_dir / "docker.log", docker_log)
    write_text(case_dir / "appsec-helper.log", helper_log)
    write_text(
        case_dir / "helper.limits.after",
        require_helper_nofile(helper_pid, args.helper_nofile),
    )

    helper_shutdown = "AppSec helper shutdown initiated" in helper_log
    open_file_limit_error = bool(
        re.search(
            r"\bEMFILE\b|Too many open files",
            helper_log + docker_log,
            re.IGNORECASE,
        )
    )
    validation = {
        "appsec_readiness_before_run": ready_before,
        "appsec_request_exec_succeeded_before_run": ready_before["ok"],
        "appsec_readiness_after_run": ready_after,
        "appsec_request_exec_succeeded_after_run": (
            ready_after["ok"] if ready_after is not None else False
        ),
        "helper_host_pid_before_run": helper_pid,
        "helper_pid_stable": helper_stable,
        "helper_shutdown_during_run": helper_shutdown,
        "open_file_limit_error_during_run": open_file_limit_error,
        "profile_complete": profile_analysis["valid"],
    }
    write_text(
        case_dir / "appsec-validation.json",
        json.dumps(validation, indent=2, sort_keys=True) + "\n",
    )

    result = summarize_case(
        case_dir,
        sampler,
        helper_sampler,
        measurement_window,
        args,
    )
    result["instrumentation"] = "ebpf-memory-profiler"
    result["memory_profiler"] = profile_analysis
    write_text(
        case_dir / "summary.json",
        json.dumps(result, indent=2, sort_keys=True) + "\n",
    )

    if ready_after is None or not ready_after["ok"]:
        failure = (
            format_appsec_readiness_failure(ready_after)
            if ready_after is not None
            else "readiness was not attempted"
        )
        raise RuntimeError(f"AppSec WAF failed post-run validation ({failure})")
    if not helper_stable:
        raise RuntimeError("AppSec helper PID changed during memory profiling")
    if helper_shutdown:
        raise RuntimeError("AppSec helper shut down during memory profiling")
    if open_file_limit_error:
        raise RuntimeError(
            "AppSec helper encountered an open-file limit error during "
            "memory profiling"
        )
    if not profile_analysis["valid"]:
        raise RuntimeError(
            "memory profile is incomplete or inconsistent: "
            + "; ".join(profile_analysis["errors"])
        )
    if result["http_req_failed_rate"] not in {None, 0.0}:
        raise RuntimeError("HTTP failures occurred during memory profiling")
    if result["dropped_iterations"] != 0.0:
        raise RuntimeError("k6 dropped iterations during memory profiling")
    return result


def validate_memory_profiler_args(args: argparse.Namespace) -> None:
    if not args.memory_profiler_bin:
        raise SystemExit(
            "--memory-profiler-bin is required by --cases memory-profiler"
        )
    profiler_bin = Path(args.memory_profiler_bin).expanduser().resolve()
    if not profiler_bin.is_file():
        raise SystemExit(
            f"--memory-profiler-bin is not a file: {profiler_bin}"
        )
    if not os.access(profiler_bin, os.X_OK):
        raise SystemExit(
            f"--memory-profiler-bin is not executable: {profiler_bin}"
        )
    args.memory_profiler_bin = str(profiler_bin)

    positive = {
        "--memory-profiler-duration": args.memory_profiler_duration,
        "--memory-profiler-max-vus": args.memory_profiler_max_vus,
        "--memory-profiler-preallocated-vus": (
            args.memory_profiler_preallocated_vus
        ),
        "--memory-profiler-rps": args.memory_profiler_rps,
        "--memory-profiler-stop-timeout": args.memory_profiler_stop_timeout,
    }
    for name, value in positive.items():
        if value <= 0:
            raise SystemExit(f"{name} must be positive")
    if (
        args.memory_profiler_max_vus
        < args.memory_profiler_preallocated_vus
    ):
        raise SystemExit(
            "--memory-profiler-max-vus must be >= "
            "--memory-profiler-preallocated-vus"
        )
    if not 1 <= args.memory_profiler_ring_buffer_mib <= 2048:
        raise SystemExit(
            "--memory-profiler-ring-buffer-mib must be between 1 and 2048"
        )
    if args.memory_profiler_stack_cache_entries < 0:
        raise SystemExit(
            "--memory-profiler-stack-cache-entries must be non-negative"
        )
    for name, value in {
        "--memory-profiler-post-load-seconds": (
            args.memory_profiler_post_load_seconds
        ),
        "--memory-profiler-settle-seconds": (
            args.memory_profiler_settle_seconds
        ),
    }.items():
        if value < 0:
            raise SystemExit(f"{name} must be non-negative")
    if not args.memory_profiler_image.strip():
        raise SystemExit("--memory-profiler-image cannot be empty")


def start_memory_profiler_container(
    args: argparse.Namespace,
    target_container_id: str,
    helper_namespace_pid: str,
    profiler_bin: Path,
    profiler_name: str,
    case_dir: Path,
) -> str:
    existing = run(
        ["docker", "container", "inspect", profiler_name],
        check=False,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    if existing.returncode == 0:
        raise RuntimeError(
            f"memory profiler container already exists: {profiler_name}"
        )

    cmd = [
        "docker",
        "run",
        "-d",
        "--name",
        profiler_name,
        "--privileged",
        "--pid",
        f"container:{target_container_id}",
        "-v",
        f"{profiler_bin}:/profiler:ro",
        args.memory_profiler_image,
        "/profiler",
        "--pid",
        helper_namespace_pid,
        "--ring-buffer-mib",
        str(args.memory_profiler_ring_buffer_mib),
        "--stack-cache-entries",
        str(args.memory_profiler_stack_cache_entries),
        "--output",
        "/tmp/outstanding-allocations.jsonl",
    ]
    container_id = capture(cmd)
    write_text(case_dir / "profiler.container-id", container_id + "\n")
    return container_id


def wait_for_memory_profiler(
    profiler_name: str,
    log_path: Path,
    timeout: float,
) -> None:
    readiness_marker = "Supports LPM trie"
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        logs = docker_container_logs(profiler_name)
        write_text(log_path, logs)
        state = docker_container_state(profiler_name)
        if readiness_marker in logs:
            return
        if not state.get("Running", False):
            raise RuntimeError(
                "memory profiler exited before attaching "
                f"(exit code {state.get('ExitCode')}); see {log_path}"
            )
        time.sleep(0.1)
    raise RuntimeError(
        f"memory profiler did not report readiness within {timeout:.1f}s; "
        f"see {log_path}"
    )


def stop_memory_profiler_container(
    profiler_name: str,
    profile_path: Path,
    case_dir: Path,
    timeout: int,
) -> None:
    stop_result = run(
        ["docker", "stop", "--timeout", str(timeout), profiler_name],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    logs = docker_container_logs(profiler_name)
    write_text(case_dir / "profiler.log", logs)
    state = docker_container_state(profiler_name)
    copy_result = run(
        [
            "docker",
            "cp",
            f"{profiler_name}:/tmp/outstanding-allocations.jsonl",
            str(profile_path),
        ],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    remove_result = run(
        ["docker", "rm", profiler_name],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    stop_summary = {
        "container_state": state,
        "copy_output": copy_result.stdout.strip(),
        "copy_returncode": copy_result.returncode,
        "remove_output": remove_result.stdout.strip(),
        "remove_returncode": remove_result.returncode,
        "stop_output": stop_result.stdout.strip(),
        "stop_returncode": stop_result.returncode,
    }
    write_text(
        case_dir / "profiler-stop.json",
        json.dumps(stop_summary, indent=2, sort_keys=True) + "\n",
    )

    if stop_result.returncode != 0:
        raise RuntimeError(
            "could not stop memory profiler container: "
            + stop_result.stdout.strip()
        )
    if copy_result.returncode != 0:
        raise RuntimeError(
            "could not copy memory profile from container: "
            + copy_result.stdout.strip()
        )
    if remove_result.returncode != 0:
        raise RuntimeError(
            "could not remove memory profiler container: "
            + remove_result.stdout.strip()
        )
    if state.get("ExitCode") != 0:
        raise RuntimeError(
            "memory profiler exited with code "
            f"{state.get('ExitCode')}; see {case_dir / 'profiler.log'}"
        )


def analyze_memory_profiler_output(
    profile_path: Path,
    helper_namespace_pid: str,
) -> dict[str, Any]:
    header: dict[str, Any] | None = None
    stack_ids: set[int] = set()
    referenced_stack_ids: set[int] = set()
    allocation_count = 0
    allocation_bytes = 0
    by_stack: dict[int, dict[str, int]] = defaultdict(
        lambda: {"count": 0, "bytes": 0}
    )

    with profile_path.open() as profile:
        for line_number, line in enumerate(profile, start=1):
            try:
                record = json.loads(line)
            except json.JSONDecodeError as error:
                raise RuntimeError(
                    f"invalid memory profiler JSON on line {line_number}: "
                    f"{error}"
                ) from error
            record_type = record.get("type")
            if record_type == "header":
                if header is not None:
                    raise RuntimeError("memory profile contains multiple headers")
                header = record
            elif record_type == "stack":
                stack_ids.add(int(record["id"]))
            elif record_type == "allocation":
                stack_id = int(record["stack_id"])
                size = int(record["size"])
                referenced_stack_ids.add(stack_id)
                allocation_count += 1
                allocation_bytes += size
                by_stack[stack_id]["count"] += 1
                by_stack[stack_id]["bytes"] += size

    errors: list[str] = []
    if header is None:
        errors.append("missing header")
        summary: dict[str, Any] = {}
    else:
        summary = header.get("summary") or {}
        if header.get("pid") != int(helper_namespace_pid):
            errors.append(
                f"header PID {header.get('pid')} does not match helper "
                f"namespace PID {helper_namespace_pid}"
            )
        if summary.get("incomplete") is not False:
            errors.append("profiler marked capture incomplete")
        if summary.get("outstanding_count") != allocation_count:
            errors.append(
                "header outstanding_count does not match allocation records"
            )
        if summary.get("outstanding_bytes") != allocation_bytes:
            errors.append(
                "header outstanding_bytes does not match allocation records"
            )

    missing_stacks = sorted(referenced_stack_ids - stack_ids)
    if missing_stacks:
        errors.append(
            "allocation records reference missing stack IDs: "
            + ",".join(str(value) for value in missing_stacks)
        )

    stack_summaries = [
        {
            "stack_id": stack_id,
            "count": values["count"],
            "bytes": values["bytes"],
        }
        for stack_id, values in sorted(
            by_stack.items(),
            key=lambda item: (-item[1]["bytes"], item[0]),
        )
    ]
    return {
        "valid": not errors,
        "errors": errors,
        "header": header,
        "record_counts": {
            "allocations": allocation_count,
            "stacks": len(stack_ids),
        },
        "outstanding_count": allocation_count,
        "outstanding_bytes": allocation_bytes,
        "allocations_by_stack": stack_summaries,
    }


def docker_container_logs(container_name: str) -> str:
    result = subprocess.run(
        ["docker", "logs", container_name],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    return result.stdout


def docker_container_state(container_name: str) -> dict[str, Any]:
    result = subprocess.run(
        [
            "docker",
            "inspect",
            "--format",
            "{{json .State}}",
            container_name,
        ],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"cannot inspect memory profiler container {container_name}: "
            f"{result.stderr.strip()}"
        )
    return json.loads(result.stdout)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def run_flamegraph_case(
    args: argparse.Namespace,
    runtime: Runtime,
    container_id: str,
) -> dict[str, Any]:
    autodiscovery = None
    if args.flamegraph_autodiscover_rps:
        autodiscovery = autodiscover_flamegraph_rps(args, runtime)
        args.flamegraph_rps = autodiscovery["selected_rps"]

    case_dir = runtime.out / "flamegraph"
    case_dir.mkdir(parents=True, exist_ok=True)

    ready_before = appsec_ready_response(runtime)
    validation = {
        "appsec_ready_http_status_before_run": ready_before.stdout or None,
        "appsec_request_exec_succeeded_before_run": (
            ready_before.returncode == 0 and ready_before.stdout == "204"
        ),
        "appsec_ready_http_status_after_run": None,
        "appsec_request_exec_succeeded_after_run": None,
        "helper_shutdown_during_run": None,
        "open_file_limit_error_during_run": None,
    }
    write_text(
        case_dir / "appsec-validation.json",
        json.dumps(validation, indent=2, sort_keys=True) + "\n",
    )
    if ready_before.returncode != 0 or ready_before.stdout != "204":
        status = ready_before.stdout or f"curl exit {ready_before.returncode}"
        raise RuntimeError(f"AppSec WAF failed pre-run validation (status: {status})")

    inspect = json.loads(capture(["docker", "inspect", container_id]))
    write_text(case_dir / "docker-inspect.json", json.dumps(inspect, indent=2))
    container_pid = str(inspect[0]["State"]["Pid"])

    docker_top, helper_pid = wait_for_helper_process(container_id, args.ready_timeout)
    write_text(case_dir / "docker-top.txt", docker_top + "\n")
    write_text(case_dir / "helper.pid", helper_pid + "\n")
    write_text(case_dir / "helper.limits.before", require_helper_nofile(helper_pid, args.helper_nofile))
    write_proc_maps(helper_pid, case_dir)
    write_text(
        case_dir / "capture-config.json",
        json.dumps(
            {
                "duration_s": args.flamegraph_duration,
                "helper_nofile": args.helper_nofile,
                "include_roles": sorted(parse_flamegraph_include(args.flamegraph_include)),
                "max_vus": args.flamegraph_max_vus,
                "monitor_interval_s": args.flamegraph_monitor_interval,
                "preallocated_vus": args.flamegraph_preallocated_vus,
                "profiler_command": args.flamegraph_profiler_command,
                "profiler_readiness_marker": "Attached tracer program",
                "profiler_post_readiness_settle_s": args.perf_warmup_seconds,
                "reporter_interval_s": args.flamegraph_reporter_interval,
                "rps": args.flamegraph_rps,
                "samples_per_second": args.flamegraph_samples_per_second,
            },
            indent=2,
            sort_keys=True,
        )
        + "\n",
    )

    cpu_stat = container_cgroup_cpu_stat(container_pid)
    if cpu_stat is not None:
        write_text(case_dir / "cgroup-cpu-stat-path.txt", str(cpu_stat) + "\n")
    sampler = CpuSampler(cpu_stat, container_id, case_dir / "cpu-samples.csv", args.cpu_sample_interval)
    helper_sampler = ProcessCpuSampler(helper_pid, case_dir / "helper-cpu-samples.csv", args.cpu_sample_interval)

    include_roles = parse_flamegraph_include(args.flamegraph_include)
    receiver_name = f"{runtime.project}-profile-receiver"
    receiver: subprocess.Popen[str] | None = None
    profiler: subprocess.Popen[str] | None = None
    try:
        receiver = start_flamegraph_receiver(args, case_dir, receiver_name)
        wait_for_receiver(args.flamegraph_collection_agent, receiver, case_dir / "receiver.wait.log", 120.0)
        profiler = start_flamegraph_profiler(args, case_dir)
        wait_for_flamegraph_profiler(
            profiler,
            case_dir / "profiler.log",
            case_dir / "profiler.wait.log",
            args.ready_timeout,
        )
        time.sleep(args.perf_warmup_seconds)
        measurement_before = capture_boundary_snapshot(
            cpu_stat, container_pid, helper_pid, scheduler_first=True
        )
        sampler.start()
        helper_sampler.start()
        run_k6(case_dir, k6_script("flamegraph", runtime.port, args))
        measurement_after = capture_boundary_snapshot(
            cpu_stat, container_pid, helper_pid, scheduler_first=False
        )
        measurement_window = MeasurementWindow(measurement_before, measurement_after)
        sampler.stop()
        helper_sampler.stop()
        time.sleep(args.flamegraph_reporter_interval + 1.0)
    finally:
        sampler.stop()
        helper_sampler.stop()
        if profiler is not None:
            terminate_process_group(profiler, "profiler", case_dir / "profiler.stop.log")
        if args.flamegraph_profiler_stop_command:
            run_stop_command(args.flamegraph_profiler_stop_command, case_dir / "profiler-stop-command.log")
        if receiver is not None:
            stop_flamegraph_receiver(receiver, receiver_name, case_dir / "receiver.stop.log")

    docker_log = capture(["docker", "logs", container_id]) + "\n"
    helper_log = capture(["docker", "exec", container_id, "cat", "/tmp/ddappsec.log"]) + "\n"
    write_text(case_dir / "docker.log", docker_log)
    write_text(case_dir / "appsec-helper.log", helper_log)
    write_text(case_dir / "helper.limits.after", require_helper_nofile(helper_pid, args.helper_nofile))
    ready_after = appsec_ready_response(runtime)
    helper_shutdown = "AppSec helper shutdown initiated" in helper_log
    open_file_limit_error = bool(
        re.search(r"\bEMFILE\b|Too many open files", helper_log + docker_log, re.IGNORECASE)
    )
    validation["appsec_ready_http_status_after_run"] = ready_after.stdout or None
    validation["appsec_request_exec_succeeded_after_run"] = (
        ready_after.returncode == 0 and ready_after.stdout == "204"
    )
    validation["helper_shutdown_during_run"] = helper_shutdown
    validation["open_file_limit_error_during_run"] = open_file_limit_error
    write_text(
        case_dir / "appsec-validation.json",
        json.dumps(validation, indent=2, sort_keys=True) + "\n",
    )

    symbols = extract_flamegraph_symbols(case_dir, container_id)
    flamegraph = build_flamegraph_artifacts(case_dir, symbols, include_roles, helper_pids={helper_pid})
    render_flamegraphs(case_dir, args)
    flamegraph = json.loads((case_dir / "flamegraph-summary.json").read_text())

    result = summarize_case(case_dir, sampler, helper_sampler, measurement_window, args)
    profile_normalization = write_profile_cpu_normalization(case_dir, flamegraph, result)
    result.update(
        {
            "flamegraph_samples": flamegraph["samples_by_role"],
            "flamegraph_folded": flamegraph["folded_files"],
            "flamegraph_svg": flamegraph["svg_files"],
            "flamegraph_unresolved_frames": flamegraph["unresolved_frames_by_mapping"],
            "flamegraph_cpu_normalization": profile_normalization,
        }
    )
    if autodiscovery is not None:
        result["flamegraph_autodiscovery"] = autodiscovery
    write_text(case_dir / "summary.json", json.dumps(result, indent=2, sort_keys=True))

    if ready_after.returncode != 0 or ready_after.stdout != "204":
        status = ready_after.stdout or f"curl exit {ready_after.returncode}"
        raise RuntimeError(f"AppSec WAF failed post-run validation (status: {status})")
    if helper_shutdown:
        raise RuntimeError("AppSec helper shut down during the flamegraph case")
    if open_file_limit_error:
        raise RuntimeError("AppSec helper encountered an open-file limit error during the flamegraph case")
    missing_roles = {"web", "helper"} - {
        role for role, samples in flamegraph["samples_by_role"].items() if samples > 0
    }
    if missing_roles:
        raise RuntimeError(
            "profiler did not capture required role(s): " + ", ".join(sorted(missing_roles))
        )
    if flamegraph["raw_profile_files"] == 0:
        raise RuntimeError("profiler did not produce any raw OTLP profile files")
    if result["http_req_failed_rate"] not in {None, 0.0}:
        raise RuntimeError("HTTP failures occurred during the flamegraph case")
    if result["dropped_iterations"] != 0.0:
        raise RuntimeError("k6 dropped iterations during the flamegraph case")
    return result


def autodiscover_flamegraph_rps(args: argparse.Namespace, runtime: Runtime) -> dict[str, Any]:
    validate_autodiscovery_args(args)
    out_dir = runtime.out / "autodiscover"
    out_dir.mkdir(parents=True, exist_ok=True)
    tested: dict[int, dict[str, Any]] = {}

    low = args.flamegraph_autodiscover_min_rps
    high = args.flamegraph_autodiscover_max_rps
    step = args.flamegraph_autodiscover_step_rps

    low_result = run_autodiscovery_probe(args, runtime, out_dir, low)
    tested[low] = low_result
    if not low_result["accepted"]:
        summary = autodiscovery_summary(args, tested, low)
        write_text(out_dir / "summary.json", json.dumps(summary, indent=2, sort_keys=True))
        return summary

    best = low
    rejected = None
    while True:
        high_result = run_autodiscovery_probe(args, runtime, out_dir, high)
        tested[high] = high_result
        if not high_result["accepted"]:
            rejected = high
            break

        best = high
        if high >= args.flamegraph_autodiscover_hard_max_rps:
            summary = autodiscovery_summary(args, tested, best, hit_hard_max=True)
            write_text(out_dir / "summary.json", json.dumps(summary, indent=2, sort_keys=True))
            print(
                f"Autodiscovered flamegraph RPS: {best} "
                f"(hit hard max without rejected probe)",
                flush=True,
            )
            return summary

        next_high = min(high * 2, args.flamegraph_autodiscover_hard_max_rps)
        next_high = round_down_to_step(next_high, step)
        if next_high <= best:
            next_high = min(best + step, args.flamegraph_autodiscover_hard_max_rps)
        if next_high <= best:
            summary = autodiscovery_summary(args, tested, best, hit_hard_max=True)
            write_text(out_dir / "summary.json", json.dumps(summary, indent=2, sort_keys=True))
            return summary
        high = next_high

    while rejected - best > step:
        candidate = round_down_to_step((best + rejected) // 2, step)
        if candidate <= best:
            candidate = best + step
        if candidate >= rejected:
            candidate = rejected - step
        candidate = round_down_to_step(candidate, step)
        if candidate in tested or candidate <= best or candidate >= rejected:
            break

        result = run_autodiscovery_probe(args, runtime, out_dir, candidate)
        tested[candidate] = result
        if result["accepted"]:
            best = candidate
        else:
            rejected = candidate

    summary = autodiscovery_summary(args, tested, best)
    write_text(out_dir / "summary.json", json.dumps(summary, indent=2, sort_keys=True))
    print(
        f"Autodiscovered flamegraph RPS: {best} "
        f"(max dropped={args.flamegraph_autodiscover_max_dropped_rate:.4f}, "
        f"max p95={args.flamegraph_autodiscover_max_p95_ms:.1f}ms)",
        flush=True,
    )
    return summary


def validate_autodiscovery_args(args: argparse.Namespace) -> None:
    if args.flamegraph_autodiscover_min_rps <= 0:
        raise SystemExit("--flamegraph-autodiscover-min-rps must be positive")
    if args.flamegraph_autodiscover_max_rps < args.flamegraph_autodiscover_min_rps:
        raise SystemExit("--flamegraph-autodiscover-max-rps must be >= --flamegraph-autodiscover-min-rps")
    if args.flamegraph_autodiscover_hard_max_rps < args.flamegraph_autodiscover_max_rps:
        raise SystemExit("--flamegraph-autodiscover-hard-max-rps must be >= --flamegraph-autodiscover-max-rps")
    if args.flamegraph_autodiscover_step_rps <= 0:
        raise SystemExit("--flamegraph-autodiscover-step-rps must be positive")
    if args.flamegraph_autodiscover_duration <= 0:
        raise SystemExit("--flamegraph-autodiscover-duration must be positive")


def run_autodiscovery_probe(
    args: argparse.Namespace,
    runtime: Runtime,
    out_dir: Path,
    rps: int,
) -> dict[str, Any]:
    probe_dir = out_dir / f"{rps}rps"
    probe_dir.mkdir(parents=True, exist_ok=True)
    script = constant_arrival_k6_script(
        runtime.port,
        rps,
        args.flamegraph_autodiscover_duration,
        args.flamegraph_autodiscover_preallocated_vus,
        args.flamegraph_autodiscover_max_vus,
    )
    run_k6(probe_dir, script)
    result = summarize_autodiscovery_probe(args, probe_dir, rps)
    write_text(probe_dir / "summary.json", json.dumps(result, indent=2, sort_keys=True))
    status = "accepted" if result["accepted"] else "rejected"
    print(
        f"Autodiscovery {status}: {rps}rps "
        f"actual={format_float(result['load_window_rps'])}rps "
        f"dropped={format_percent(result['dropped_iterations_rate'])} "
        f"p95={format_float(result['http_req_duration_p95_ms'])}ms",
        flush=True,
    )
    return result


def summarize_autodiscovery_probe(
    args: argparse.Namespace,
    probe_dir: Path,
    rps: int,
) -> dict[str, Any]:
    summary_path = probe_dir / "k6-summary.json"
    k6_summary = json.loads(summary_path.read_text()) if summary_path.exists() else {}
    http_reqs_count = metric_value(k6_summary, "http_reqs", "count")
    dropped_iterations = metric_value(k6_summary, "dropped_iterations", "count") or 0.0
    scheduled_iterations = http_reqs_count + dropped_iterations if http_reqs_count is not None else None
    dropped_rate = dropped_iterations / scheduled_iterations if scheduled_iterations else None
    failed_rate = metric_value(k6_summary, "http_req_failed", "value")
    p95 = metric_value(k6_summary, "http_req_duration", "p(95)")
    load_window_rps = (
        http_reqs_count / args.flamegraph_autodiscover_duration if http_reqs_count is not None else None
    )
    accepted = (
        http_reqs_count is not None
        and (failed_rate is None or failed_rate == 0)
        and (dropped_rate is None or dropped_rate <= args.flamegraph_autodiscover_max_dropped_rate)
        and (p95 is None or p95 <= args.flamegraph_autodiscover_max_p95_ms)
    )
    return {
        "rps": rps,
        "accepted": accepted,
        "load_window_rps": load_window_rps,
        "http_reqs_count": http_reqs_count,
        "http_req_failed_rate": failed_rate,
        "dropped_iterations": dropped_iterations,
        "dropped_iterations_rate": dropped_rate,
        "http_req_duration_med_ms": metric_value(k6_summary, "http_req_duration", "med"),
        "http_req_duration_p95_ms": p95,
        "http_req_duration_p99_ms": metric_value(k6_summary, "http_req_duration", "p(99)"),
        "http_req_duration_max_ms": metric_value(k6_summary, "http_req_duration", "max"),
    }


def autodiscovery_summary(
    args: argparse.Namespace,
    tested: dict[int, dict[str, Any]],
    selected_rps: int,
    hit_hard_max: bool = False,
) -> dict[str, Any]:
    return {
        "selected_rps": selected_rps,
        "min_rps": args.flamegraph_autodiscover_min_rps,
        "max_rps": args.flamegraph_autodiscover_max_rps,
        "hard_max_rps": args.flamegraph_autodiscover_hard_max_rps,
        "hit_hard_max": hit_hard_max,
        "step_rps": args.flamegraph_autodiscover_step_rps,
        "duration_s": args.flamegraph_autodiscover_duration,
        "max_dropped_rate": args.flamegraph_autodiscover_max_dropped_rate,
        "max_p95_ms": args.flamegraph_autodiscover_max_p95_ms,
        "probes": [tested[rps] for rps in sorted(tested)],
    }


def round_down_to_step(value: int, step: int) -> int:
    return (value // step) * step


def format_float(value: Any, digits: int = 2) -> str:
    return "-" if value is None else f"{float(value):.{digits}f}"


def format_percent(value: Any, digits: int = 2) -> str:
    return "-" if value is None else f"{float(value) * 100.0:.{digits}f}%"


def parse_flamegraph_include(raw: str) -> set[str]:
    roles = {role.strip() for role in raw.split(",") if role.strip()}
    valid = {"web", "helper", "other"}
    invalid = roles - valid
    if invalid:
        raise SystemExit(f"invalid --flamegraph-include value(s): {', '.join(sorted(invalid))}")
    return roles


def start_flamegraph_receiver(
    args: argparse.Namespace,
    case_dir: Path,
    receiver_name: str,
) -> subprocess.Popen[str]:
    receiver_command = shlex.split(args.flamegraph_profile_receiver_command)
    if not receiver_command:
        raise SystemExit("--flamegraph-profile-receiver-command cannot be empty")
    if shutil.which(receiver_command[0]) is None:
        raise SystemExit(
            f"profile receiver command not found: {receiver_command[0]}\n"
            "Run `uv run ./run-benchmark.py prepare-flamegraph-tools` first."
        )

    raw_dir = case_dir / "raw"
    raw_dir.mkdir(parents=True, exist_ok=True)

    log_file = (case_dir / "receiver.log").open("w")
    cmd = [
        *receiver_command,
        "-addr",
        args.flamegraph_collection_agent,
        "-out",
        str(raw_dir),
    ]
    print("+ " + " ".join(shlex.quote(part) for part in cmd), flush=True)
    return subprocess.Popen(cmd, stdout=log_file, stderr=subprocess.STDOUT, text=True, start_new_session=True)


def wait_for_receiver(
    address: str,
    process: subprocess.Popen[str],
    log_path: Path,
    timeout: float,
) -> None:
    if ":" not in address:
        raise RuntimeError(f"invalid receiver address: {address}")
    host, port_text = address.rsplit(":", 1)
    host = "127.0.0.1" if host in {"", "0.0.0.0"} else host.strip("[]")
    port = int(port_text)
    deadline = time.monotonic() + timeout
    last_error: OSError | None = None
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError(f"profile receiver exited before listening; see {log_path.parent / 'receiver.log'}")
        try:
            with socket.create_connection((host, port), timeout=0.25):
                write_text(log_path, f"receiver ready at {address}\n")
                return
        except OSError as error:
            last_error = error
            time.sleep(0.25)
    raise RuntimeError(f"profile receiver did not listen at {address}: {last_error}")


def profile_receiver_source() -> str:
    return r'''
package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"path/filepath"
	"sync/atomic"

	"go.opentelemetry.io/collector/pdata/pprofile/pprofileotlp"
	"google.golang.org/grpc"
	_ "google.golang.org/grpc/encoding/gzip"
)

type profileServer struct {
	pprofileotlp.UnimplementedGRPCServer
	outDir string
	seq    atomic.Uint64
}

func (s *profileServer) Export(_ context.Context, req pprofileotlp.ExportRequest) (pprofileotlp.ExportResponse, error) {
	data, err := req.MarshalJSON()
	if err != nil {
		return pprofileotlp.NewExportResponse(), err
	}
	name := filepath.Join(s.outDir, fmt.Sprintf("profiles-%06d.json", s.seq.Add(1)))
	if err := os.WriteFile(name, data, 0644); err != nil {
		return pprofileotlp.NewExportResponse(), err
	}
	log.Printf("wrote %s", name)
	return pprofileotlp.NewExportResponse(), nil
}

func main() {
	addr := flag.String("addr", "127.0.0.1:11001", "listen address")
	outDir := flag.String("out", "/out", "output directory")
	flag.Parse()

	if err := os.MkdirAll(*outDir, 0755); err != nil {
		log.Fatal(err)
	}
	listener, err := net.Listen("tcp", *addr)
	if err != nil {
		log.Fatal(err)
	}
	server := grpc.NewServer()
	pprofileotlp.RegisterGRPCServer(server, &profileServer{outDir: *outDir})
	log.Printf("listening on %s", *addr)
	if err := server.Serve(listener); err != nil {
		log.Fatal(err)
	}
}
'''.lstrip()


def start_flamegraph_profiler(args: argparse.Namespace, case_dir: Path) -> subprocess.Popen[str]:
    profiler_command = shlex.split(args.flamegraph_profiler_command)
    if not profiler_command:
        raise SystemExit("--flamegraph-profiler-command cannot be empty")
    if shutil.which(profiler_command[0]) is None:
        raise SystemExit(f"profiler command not found: {profiler_command[0]}")

    cmd = [
        *profiler_command,
        f"-collection-agent={args.flamegraph_collection_agent}",
        "-disable-tls",
        f"-samples-per-second={args.flamegraph_samples_per_second}",
        f"-reporter-interval={args.flamegraph_reporter_interval}s",
        f"-monitor-interval={args.flamegraph_monitor_interval}s",
        "-send-error-frames",
    ]
    log_file = (case_dir / "profiler.log").open("w")
    print("+ " + " ".join(shlex.quote(part) for part in cmd), flush=True)
    return subprocess.Popen(cmd, stdout=log_file, stderr=subprocess.STDOUT, text=True, start_new_session=True)


def wait_for_flamegraph_profiler(
    process: subprocess.Popen[str],
    profiler_log_path: Path,
    wait_log_path: Path,
    timeout: float,
) -> None:
    readiness_marker = "Attached tracer program"
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError(
                f"profiler exited before attaching; see {profiler_log_path}"
            )
        try:
            profiler_log = profiler_log_path.read_text(errors="replace")
        except OSError:
            profiler_log = ""
        if readiness_marker in profiler_log:
            write_text(wait_log_path, f"profiler ready: {readiness_marker}\n")
            return
        time.sleep(0.1)
    raise RuntimeError(
        f"profiler did not report readiness within {timeout:.1f}s; "
        f"see {profiler_log_path}"
    )


def terminate_process_group(process: subprocess.Popen[str], name: str, log_path: Path) -> None:
    if process.poll() is not None:
        return

    messages: list[str] = []
    for sig, label in [(signal.SIGTERM, "SIGTERM"), (signal.SIGKILL, "SIGKILL")]:
        try:
            os.killpg(process.pid, sig)
            messages.append(f"sent {label} to {name} process group {process.pid}")
        except ProcessLookupError:
            return
        except PermissionError as error:
            messages.append(f"could not send {label} to {name} process group {process.pid}: {error}")
            break
        try:
            process.wait(timeout=5)
            write_text(log_path, "\n".join(messages) + "\n")
            return
        except subprocess.TimeoutExpired:
            continue
    write_text(log_path, "\n".join(messages) + f"\n{name} may still be running\n")


def run_stop_command(command: str, log_path: Path) -> None:
    cmd = shlex.split(command)
    if not cmd:
        return
    with log_path.open("w") as log_file:
        print("+ " + " ".join(shlex.quote(part) for part in cmd), flush=True)
        subprocess.run(cmd, stdout=log_file, stderr=subprocess.STDOUT, text=True, check=False)


def stop_flamegraph_receiver(
    process: subprocess.Popen[str],
    receiver_name: str,
    log_path: Path,
) -> None:
    terminate_process_group(process, "receiver", log_path)


def extract_flamegraph_symbols(case_dir: Path, container_id: str) -> dict[str, Path]:
    symbols_dir = case_dir / "symbols"
    symbols_dir.mkdir(parents=True, exist_ok=True)
    queries = {
        "frankenphp": "command -v frankenphp || true",
        "libphp.so": "find /usr/local/lib /usr/lib -type f -name 'libphp.so*' 2>/dev/null | head -n 1",
        "ddtrace.so": "ext_dir=$(php -r 'echo ini_get(\"extension_dir\");'); test -f \"$ext_dir/ddtrace.so\" && echo \"$ext_dir/ddtrace.so\" || true",
        "ddappsec.so": "ext_dir=$(php -r 'echo ini_get(\"extension_dir\");'); test -f \"$ext_dir/ddappsec.so\" && echo \"$ext_dir/ddappsec.so\" || true",
        "libddappsec-helper-rust.so": "find /opt/datadog /usr/local /usr/lib -type f -name 'libddappsec-helper-rust.so' 2>/dev/null | head -n 1",
    }

    symbols: dict[str, Path] = {}
    missing: list[str] = []
    for name, query in queries.items():
        source = capture(["docker", "exec", container_id, "sh", "-lc", query])
        if not source:
            missing.append(name)
            continue
        destination = symbols_dir / name
        result = run(["docker", "cp", f"{container_id}:{source}", str(destination)], check=False)
        if result.returncode == 0:
            symbols[name] = destination
        else:
            missing.append(f"{name} ({source})")

    if missing:
        write_text(symbols_dir / "missing.txt", "\n".join(missing) + "\n")
    return symbols


def build_flamegraph_artifacts(
    case_dir: Path,
    symbols: dict[str, Path],
    include_roles: set[str],
    helper_pids: set[str] | None = None,
) -> dict[str, Any]:
    raw_dir = case_dir / "raw"
    folded_by_role: dict[str, Counter[str]] = defaultdict(Counter)
    samples_by_role: Counter[str] = Counter()
    unresolved_by_mapping: Counter[str] = Counter()
    raw_files = sorted(raw_dir.glob("profiles-*.json"))
    symbol_cache = load_symbol_cache(case_dir)
    symbolization = populate_symbol_cache(case_dir, raw_files, symbols, include_roles, symbol_cache, helper_pids)

    for raw_file in raw_files:
        data = json.loads(raw_file.read_text())
        dictionary = data.get("dictionary", {})
        for resource_profile in data.get("resourceProfiles", []):
            resource_attrs = attributes_to_dict(resource_profile.get("resource", {}).get("attributes", []), dictionary)
            for scope_profile in resource_profile.get("scopeProfiles", []):
                for profile in scope_profile.get("profiles", []):
                    for sample in profile.get("samples", []):
                        frames = sample_frames(sample, dictionary)
                        if not frames:
                            continue
                        role = classify_stack(resource_attrs, frames, helper_pids)
                        weight = len(sample.get("timestampsUnixNano") or []) or 1
                        samples_by_role[role] += weight
                        if role not in include_roles:
                            continue

                        labels: list[str] = [flamegraph_root(role, resource_attrs)]
                        for frame in reversed(frames):
                            label, unresolved_mapping = frame_label(frame, symbols, symbol_cache)
                            labels.append(label)
                            if unresolved_mapping is not None:
                                unresolved_by_mapping[unresolved_mapping] += weight
                        folded_by_role[role][";".join(labels)] += weight

    folded_files: dict[str, str] = {}
    combined = Counter()
    for role, stacks in sorted(folded_by_role.items()):
        combined.update(stacks)
        path = case_dir / f"folded.{role}.txt"
        write_folded(path, stacks)
        folded_files[role] = str(path)

    combined_path = case_dir / "folded.combined.txt"
    write_folded(combined_path, combined)
    folded_files["combined"] = str(combined_path)

    summary = {
        "raw_profile_files": len(raw_files),
        "included_roles": sorted(include_roles),
        "samples_by_role": dict(sorted(samples_by_role.items())),
        "folded_files": folded_files,
        "unresolved_frames_by_mapping": dict(sorted(unresolved_by_mapping.items())),
        "symbolization": symbolization,
        "svg_files": {},
    }
    write_text(case_dir / "flamegraph-summary.json", json.dumps(summary, indent=2, sort_keys=True))
    return summary


def write_profile_cpu_normalization(
    case_dir: Path,
    flamegraph: dict[str, Any],
    result: dict[str, Any],
) -> dict[str, Any]:
    container_cpu = result.get("container_cpu_ms_per_req")
    helper_cpu = result.get("helper_cpu_ms_per_req")
    web_cpu = None
    if isinstance(container_cpu, (int, float)) and isinstance(helper_cpu, (int, float)):
        remaining = float(container_cpu) - float(helper_cpu)
        if remaining >= 0:
            web_cpu = remaining

    measured_cpu = {
        "helper": float(helper_cpu) if isinstance(helper_cpu, (int, float)) else None,
        "web": web_cpu,
    }
    cpu_sources = {
        "helper": result.get("helper_cpu_source"),
        "web": (
            f"{result.get('container_cpu_source')} minus {result.get('helper_cpu_source')}"
            if web_cpu is not None
            else None
        ),
    }
    roles: dict[str, dict[str, Any]] = {}
    combined_rows: list[str] = [
        "role\tsamples\tsample_share_within_role\tcpu_ms_per_request\tstack"
    ]

    for role in ("helper", "web"):
        folded_path = case_dir / f"folded.{role}.txt"
        if not folded_path.exists():
            continue

        stacks: list[tuple[str, int]] = []
        for line in folded_path.read_text().splitlines():
            folded, weight_text = line.rsplit(" ", 1)
            stacks.append((folded, int(weight_text)))
        stacks.sort(key=lambda item: (-item[1], item[0]))

        samples = sum(weight for _, weight in stacks)
        role_cpu = measured_cpu[role]
        cpu_per_sample = role_cpu / samples if role_cpu is not None and samples > 0 else None
        normalized_path = case_dir / f"profile-cpu.{role}.tsv"
        rows = ["samples\tsample_share\tcpu_ms_per_request\tstack"]
        for stack, weight in stacks:
            share = weight / samples if samples else 0.0
            stack_cpu = weight * cpu_per_sample if cpu_per_sample is not None else None
            cpu_text = f"{stack_cpu:.12f}" if stack_cpu is not None else ""
            clean_stack = stack.replace("\t", " ")
            rows.append(f"{weight}\t{share:.12f}\t{cpu_text}\t{clean_stack}")
            combined_rows.append(
                f"{role}\t{weight}\t{share:.12f}\t{cpu_text}\t{clean_stack}"
            )
        write_text(normalized_path, "\n".join(rows) + "\n")

        roles[role] = {
            "cpu_ms_per_request": role_cpu,
            "cpu_ms_per_sample": cpu_per_sample,
            "cpu_source": cpu_sources[role],
            "normalized_stacks_file": str(normalized_path),
            "samples": samples,
            "samples_reported_by_profiler": flamegraph.get("samples_by_role", {}).get(role),
        }

    combined_path = case_dir / "profile-cpu.combined.tsv"
    write_text(combined_path, "\n".join(combined_rows) + "\n")
    summary = {
        "method": "measured role CPU distributed by each stack's share of role samples",
        "combined_stacks_file": str(combined_path),
        "roles": roles,
        "web_cpu_derivation": "container CPU minus helper CPU",
    }
    summary_path = case_dir / "profile-normalization.json"
    write_text(summary_path, json.dumps(summary, indent=2, sort_keys=True) + "\n")
    return {**summary, "summary_file": str(summary_path)}


def render_flamegraphs(case_dir: Path, args: argparse.Namespace) -> None:
    command = shlex.split(args.flamegraph_render_command) if args.flamegraph_render_command else []
    if not command and shutil.which("flamegraph.pl") is not None:
        command = ["flamegraph.pl"]
    if not command:
        write_text(
            case_dir / "render-flamegraph.txt",
            "Install FlameGraph and run, for example:\n"
            "  flamegraph.pl folded.combined.txt > flamegraph.combined.svg\n",
        )
        return

    svg_files: dict[str, str] = {}
    for folded in sorted(case_dir.glob("folded.*.txt")):
        svg = case_dir / folded.name.replace("folded.", "flamegraph.").replace(".txt", ".svg")
        with folded.open() as stdin, svg.open("w") as stdout:
            result = subprocess.run(command, stdin=stdin, stdout=stdout, stderr=subprocess.PIPE, text=True, check=False)
        if result.returncode == 0:
            svg_files[folded.stem.removeprefix("folded.")] = str(svg)
        else:
            write_text(svg.with_suffix(".svg.error"), result.stderr)
            try:
                svg.unlink()
            except FileNotFoundError:
                pass

    summary_path = case_dir / "flamegraph-summary.json"
    summary = json.loads(summary_path.read_text()) if summary_path.exists() else {}
    summary["svg_files"] = svg_files
    write_text(summary_path, json.dumps(summary, indent=2, sort_keys=True))


def sample_frames(sample: dict[str, Any], dictionary: dict[str, Any]) -> list[dict[str, Any]]:
    stack_index = to_int(sample.get("stackIndex"))
    stack_table = dictionary.get("stackTable", [])
    if stack_index is None or stack_index >= len(stack_table):
        return []

    stack = stack_table[stack_index]
    frames: list[dict[str, Any]] = []
    for location_index in stack.get("locationIndices", []):
        location = table_item(dictionary.get("locationTable", []), location_index)
        if not location:
            continue
        mapping = table_item(dictionary.get("mappingTable", []), location.get("mappingIndex"))
        mapping_name = string_table_value(dictionary, mapping.get("filenameStrindex")) if mapping else ""
        function_name = location_function_name(location, dictionary)
        frames.append(
            {
                "mapping": Path(mapping_name).name,
                "mapping_path": mapping_name,
                "mapping_entry": mapping,
                "function": function_name,
                "address": to_int(location.get("address")),
            }
        )
    return frames


def location_function_name(location: dict[str, Any], dictionary: dict[str, Any]) -> str | None:
    for line in location.get("lines", []):
        function = table_item(dictionary.get("functionTable", []), line.get("functionIndex"))
        if not function:
            continue
        name = string_table_value(dictionary, function.get("nameStrindex"))
        if name:
            return name
    return None


def classify_stack(
    resource_attrs: dict[str, Any],
    frames: list[dict[str, Any]],
    helper_pids: set[str] | None = None,
) -> str:
    process_pid = str(resource_attrs.get("process.pid") or "")
    if helper_pids is not None and process_pid in helper_pids:
        return "helper"

    process_name = Path(str(resource_attrs.get("process.executable.name") or "")).name
    process_path = Path(str(resource_attrs.get("process.executable.path") or "")).name
    frame_mappings = {str(frame.get("mapping") or "") for frame in frames}
    process = " ".join([process_name, process_path])
    if "libddappsec-helper-rust.so" in frame_mappings or "ddappsec-helper" in process or "helper-rust" in process:
        return "helper"
    if frame_mappings & {"frankenphp", "libphp.so", "ddtrace.so", "ddappsec.so"} or "frankenphp" in process:
        return "web"
    return "other"


def flamegraph_root(role: str, resource_attrs: dict[str, Any]) -> str:
    if role == "web":
        return "web/frankenphp"
    if role == "helper":
        return "helper/libddappsec-helper-rust"
    process = Path(str(resource_attrs.get("process.executable.name") or resource_attrs.get("process.executable.path") or "unknown")).name
    return f"other/{safe_frame_name(process)}"


def frame_label(
    frame: dict[str, Any],
    symbols: dict[str, Path],
    symbol_cache: dict[tuple[str, int], str | None],
) -> tuple[str, str | None]:
    mapping = str(frame.get("mapping") or "unknown")
    function = frame.get("function")
    if function:
        return safe_frame_name(str(function)), None

    address = frame.get("address")
    if not isinstance(address, int):
        return safe_frame_name(mapping), mapping

    symbol = cached_symbol(frame, symbols, symbol_cache)
    if symbol:
        return safe_frame_name(f"{symbol} [{mapping}]"), None
    return safe_frame_name(f"{mapping}@0x{address:x}"), mapping


def cached_symbol(
    frame: dict[str, Any],
    symbols: dict[str, Path],
    symbol_cache: dict[tuple[str, int], str | None],
) -> str | None:
    mapping = str(frame.get("mapping") or "")
    if mapping not in symbols:
        return None

    for address in candidate_symbol_addresses(frame):
        symbol = symbol_cache.get((mapping, address))
        if symbol:
            return symbol
    return None


def populate_symbol_cache(
    case_dir: Path,
    raw_files: list[Path],
    symbols: dict[str, Path],
    include_roles: set[str],
    symbol_cache: dict[tuple[str, int], str | None],
    helper_pids: set[str] | None = None,
) -> dict[str, Any]:
    stats: dict[str, Any] = {
        "cache_file": str(symbol_cache_path(case_dir)),
        "cached_entries_before": len(symbol_cache),
        "new_addresses": 0,
        "addr2line_invocations": 0,
        "addr2line_available": shutil.which("addr2line") is not None,
        "addresses_by_mapping": {},
    }
    if not stats["addr2line_available"] or not symbols:
        save_symbol_cache(case_dir, symbol_cache)
        stats["cached_entries_after"] = len(symbol_cache)
        return stats

    addresses_by_mapping: dict[str, set[int]] = defaultdict(set)
    for raw_file in raw_files:
        data = json.loads(raw_file.read_text())
        dictionary = data.get("dictionary", {})
        for resource_profile in data.get("resourceProfiles", []):
            resource_attrs = attributes_to_dict(resource_profile.get("resource", {}).get("attributes", []), dictionary)
            for scope_profile in resource_profile.get("scopeProfiles", []):
                for profile in scope_profile.get("profiles", []):
                    for sample in profile.get("samples", []):
                        frames = sample_frames(sample, dictionary)
                        if not frames or classify_stack(resource_attrs, frames, helper_pids) not in include_roles:
                            continue
                        for frame in frames:
                            if frame.get("function"):
                                continue
                            mapping = str(frame.get("mapping") or "")
                            if mapping not in symbols:
                                continue
                            for address in candidate_symbol_addresses(frame):
                                if (mapping, address) not in symbol_cache:
                                    addresses_by_mapping[mapping].add(address)

    for mapping, addresses in sorted(addresses_by_mapping.items()):
        address_list = sorted(addresses)
        stats["addresses_by_mapping"][mapping] = len(address_list)
        for resolved, invocations in run_addr2line_batch(symbols[mapping], address_list):
            stats["addr2line_invocations"] += invocations
            symbol_cache.update(((mapping, address), symbol) for address, symbol in resolved.items())

    stats["new_addresses"] = sum(stats["addresses_by_mapping"].values())
    stats["cached_entries_after"] = len(symbol_cache)
    save_symbol_cache(case_dir, symbol_cache)
    return stats


def run_addr2line_batch(symbol_file: Path, addresses: list[int]) -> list[tuple[dict[int, str | None], int]]:
    if not addresses:
        return []

    chunks: list[tuple[dict[int, str | None], int]] = []
    for start in range(0, len(addresses), 1024):
        chunk = addresses[start : start + 1024]
        command = ["addr2line", "-Cfpe", str(symbol_file), *(f"0x{address:x}" for address in chunk)]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, check=False)
        resolved: dict[int, str | None] = {}
        if result.returncode == 0:
            lines = result.stdout.splitlines()
            for address, line in zip(chunk, lines):
                resolved[address] = parse_addr2line_line(line)
        for address in chunk:
            resolved.setdefault(address, None)
        chunks.append((resolved, 1))
    return chunks


def parse_addr2line_line(output: str) -> str | None:
    output = output.strip()
    if not output or output.startswith("??") or "??:0" in output:
        return None
    return output.split(" at ", 1)[0]


def load_symbol_cache(case_dir: Path) -> dict[tuple[str, int], str | None]:
    path = symbol_cache_path(case_dir)
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text())
    except json.JSONDecodeError:
        return {}
    entries = data.get("entries", {}) if isinstance(data, dict) else {}
    cache: dict[tuple[str, int], str | None] = {}
    for key, value in entries.items():
        if "|" not in key:
            continue
        mapping, address_text = key.split("|", 1)
        try:
            cache[(mapping, int(address_text, 16))] = value if value is None else str(value)
        except ValueError:
            continue
    return cache


def save_symbol_cache(case_dir: Path, symbol_cache: dict[tuple[str, int], str | None]) -> None:
    entries = {symbol_cache_key(mapping, address): symbol for (mapping, address), symbol in sorted(symbol_cache.items())}
    write_text(symbol_cache_path(case_dir), json.dumps({"version": 1, "entries": entries}, indent=2, sort_keys=True))


def symbol_cache_path(case_dir: Path) -> Path:
    return case_dir / "symbol-cache.json"


def symbol_cache_key(mapping: str, address: int) -> str:
    return f"{mapping}|0x{address:x}"


def candidate_symbol_addresses(frame: dict[str, Any]) -> list[int]:
    address = frame.get("address")
    if not isinstance(address, int):
        return []
    candidates = [address]
    mapping = frame.get("mapping_entry") or {}
    memory_start = to_int(mapping.get("memoryStart"))
    file_offset = to_int(mapping.get("fileOffset")) or 0
    if memory_start is not None and address >= memory_start:
        candidates.append(address - memory_start + file_offset)
    return list(dict.fromkeys(candidate for candidate in candidates if candidate >= 0))


def write_folded(path: Path, stacks: Counter[str]) -> None:
    lines = [f"{stack} {count}" for stack, count in sorted(stacks.items())]
    write_text(path, "\n".join(lines) + ("\n" if lines else ""))


def attributes_to_dict(attributes: list[dict[str, Any]], dictionary: dict[str, Any]) -> dict[str, Any]:
    return {attribute_key(attribute, dictionary): attribute_value(attribute.get("value", {})) for attribute in attributes}


def attribute_key(attribute: dict[str, Any], dictionary: dict[str, Any]) -> str:
    if "key" in attribute:
        return str(attribute["key"])
    return string_table_value(dictionary, attribute.get("keyStrindex"))


def attribute_value(value: dict[str, Any]) -> Any:
    if not isinstance(value, dict) or not value:
        return None
    return next(iter(value.values()))


def string_table_value(dictionary: dict[str, Any], index: Any) -> str:
    item = table_item(dictionary.get("stringTable", []), index)
    return str(item) if item is not None else ""


def table_item(table: list[Any], index: Any) -> Any:
    parsed = to_int(index)
    if parsed is None or parsed < 0 or parsed >= len(table):
        return None
    return table[parsed]


def to_int(value: Any) -> int | None:
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def safe_frame_name(value: str) -> str:
    return value.replace(";", ",").replace("\n", " ").strip() or "unknown"


def run_k6(case_dir: Path, script: str) -> None:
    write_text(case_dir / "k6.js", script)
    with (case_dir / "k6.log").open("w") as log_file:
        run(
            [
                "docker",
                "run",
                "--rm",
                "--network",
                "host",
                "--user",
                f"{os.getuid()}:{os.getgid()}",
                "-v",
                f"{case_dir.resolve()}:/out:rw",
                "-i",
                "grafana/k6",
                "run",
                "--summary-export",
                "/out/k6-summary.json",
                "-",
            ],
            stdin=script,
            stdout=log_file,
            stderr=subprocess.STDOUT,
            check=False,
        )


def k6_script(mode: str, port: int, args: argparse.Namespace) -> str:
    if mode == "saturated":
        return textwrap.dedent(
            f"""
            import http from "k6/http";
            export const options = {{
              vus: {args.saturated_vus},
              duration: "{case_duration(mode, args)}s",
              summaryTrendStats: ["avg", "min", "med", "p(90)", "p(95)", "p(99)", "max"]
            }};
            export default function () {{ http.get("http://127.0.0.1:{port}/"); }}
            """
        ).lstrip()

    duration = case_duration(mode, args)
    rate = args.flamegraph_rps if mode == "flamegraph" else args.fixed_rps
    preallocated_vus = args.flamegraph_preallocated_vus if mode == "flamegraph" else args.fixed_preallocated_vus
    max_vus = args.flamegraph_max_vus if mode == "flamegraph" else args.fixed_max_vus
    return constant_arrival_k6_script(port, rate, duration, preallocated_vus, max_vus)


def constant_arrival_k6_script(
    port: int,
    rate: int,
    duration: int,
    preallocated_vus: int,
    max_vus: int,
) -> str:
    return textwrap.dedent(
        f"""
        import http from "k6/http";
        export const options = {{
          scenarios: {{
            fixed: {{
              executor: "constant-arrival-rate",
              rate: {rate},
              timeUnit: "1s",
              duration: "{duration}s",
              preAllocatedVUs: {preallocated_vus},
              maxVUs: {max_vus}
            }}
          }},
          summaryTrendStats: ["avg", "min", "med", "p(90)", "p(95)", "p(99)", "max"]
        }};
        export default function () {{ http.get("http://127.0.0.1:{port}/"); }}
        """
    ).lstrip()


def summarize_case(
    case_dir: Path,
    sampler: CpuSampler,
    helper_sampler: "ProcessCpuSampler",
    measurement_window: MeasurementWindow,
    args: argparse.Namespace,
) -> dict[str, Any]:
    summary_path = case_dir / "k6-summary.json"
    k6_summary = json.loads(summary_path.read_text()) if summary_path.exists() else {}
    task_clock_ms, perf_elapsed_s = parse_perf_task_clock(case_dir)
    completion_rps = metric_value(k6_summary, "http_reqs", "rate")
    http_reqs_count = metric_value(k6_summary, "http_reqs", "count")
    dropped_iterations = metric_value(k6_summary, "dropped_iterations", "count") or 0.0
    scheduled_iterations = http_reqs_count + dropped_iterations if http_reqs_count is not None else None
    dropped_iterations_rate = (
        dropped_iterations / scheduled_iterations if scheduled_iterations and scheduled_iterations > 0 else None
    )
    duration = case_duration(case_dir.name, args)
    load_window_rps = http_reqs_count / duration if http_reqs_count is not None else None
    load_model, load, target_rps, vus = load_spec(case_dir.name, args)
    boundary = summarize_measurement_window(measurement_window, http_reqs_count)
    write_text(
        case_dir / "boundary-counters.json",
        json.dumps(boundary, indent=2, sort_keys=True) + "\n",
    )

    helper_measurement = boundary["cpu"]["helper"]
    helper_cpu_pct = helper_measurement["cpu_pct"]
    helper_cpu_ms_per_req = helper_measurement["cpu_ms_per_request"]
    helper_cpu_source = "procfs-boundary" if helper_cpu_ms_per_req is not None else None
    if helper_cpu_ms_per_req is None:
        helper_cpu_pct = helper_sampler.average
        helper_cpu_ms_per_req = cpu_ms_per_request(helper_cpu_pct, load_window_rps)
        helper_cpu_source = "procfs-sampler" if helper_cpu_pct is not None else None

    container_measurement = boundary["cpu"]["container"]
    container_cpu_avg_pct = container_measurement["cpu_pct"]
    container_cpu_ms_per_req = container_measurement["cpu_ms_per_request"]
    container_cpu_source = "cgroup-boundary" if container_cpu_ms_per_req is not None else None
    if container_cpu_ms_per_req is None:
        container_cpu_avg_pct = sampler.average
        container_cpu_ms_per_req = cpu_ms_per_request(container_cpu_avg_pct, load_window_rps)
        container_cpu_source = "cgroup-sampler" if sampler.cpu_stat is not None else "docker-stats-sampler"

    result = {
        "case": case_dir.name,
        "instrumentation": "otel" if case_dir.name == "flamegraph" else args.instrumentation,
        "load_model": load_model,
        "load": load,
        "target_rps": target_rps,
        "vus": vus,
        "active_rps": load_window_rps,
        "load_window_rps": load_window_rps,
        "completion_rps": completion_rps,
        "http_reqs_count": http_reqs_count,
        "http_req_failed_rate": metric_value(k6_summary, "http_req_failed", "value"),
        "dropped_iterations": dropped_iterations,
        "dropped_iterations_rate": dropped_iterations_rate,
        "http_req_duration_avg_ms": metric_value(k6_summary, "http_req_duration", "avg"),
        "http_req_duration_med_ms": metric_value(k6_summary, "http_req_duration", "med"),
        "http_req_duration_p95_ms": metric_value(k6_summary, "http_req_duration", "p(95)"),
        "http_req_duration_p99_ms": metric_value(k6_summary, "http_req_duration", "p(99)"),
        "http_req_duration_max_ms": metric_value(k6_summary, "http_req_duration", "max"),
        "helper_task_clock_ms": task_clock_ms,
        "helper_perf_elapsed_s": perf_elapsed_s,
        "helper_cpu_pct": helper_cpu_pct,
        "helper_cpu_source": helper_cpu_source,
        "helper_cpu_ms_per_req": helper_cpu_ms_per_req,
        "container_cpu_avg_pct": container_cpu_avg_pct,
        "container_cpu_source": container_cpu_source,
        "container_cpu_ms_per_req": container_cpu_ms_per_req,
        "measurement_window_s": boundary["elapsed_s"],
        "scheduler": boundary["scheduler"],
    }
    write_text(case_dir / "summary.json", json.dumps(result, indent=2, sort_keys=True))
    return result


def load_spec(mode: str, args: argparse.Namespace) -> tuple[str, str, int | None, int | None]:
    if mode == "saturated":
        return "closed-loop-vus", f"{args.saturated_vus}vus", None, args.saturated_vus

    if mode == "flamegraph":
        return "constant-arrival-rate", f"{args.flamegraph_rps}rps", args.flamegraph_rps, None

    if mode == "memory-profiler":
        return (
            "constant-arrival-rate",
            f"{args.memory_profiler_rps}rps",
            args.memory_profiler_rps,
            None,
        )

    return "constant-arrival-rate", f"{args.fixed_rps}rps", args.fixed_rps, None


def case_duration(mode: str, args: argparse.Namespace) -> int:
    if mode == "flamegraph":
        return args.flamegraph_duration
    if mode == "memory-profiler":
        return args.memory_profiler_duration
    return args.duration


def print_results(results: list[dict[str, Any]]) -> None:
    def fmt(value: Any, digits: int = 2) -> str:
        if value is None:
            return "-"
        if isinstance(value, float):
            return f"{value:.{digits}f}"
        return str(value)

    headers = [
        "case",
        "load",
        "rps",
        "reqs",
        "fail%",
        "dropped%",
        "cpu_ms/req",
        "helper_ms/req",
        "p50_ms",
        "p95_ms",
        "p99_ms",
        "max_ms",
    ]
    rows = [
        [
            result["case"],
            result["load"],
            fmt(result["load_window_rps"]),
            fmt(result["http_reqs_count"], 0),
            fmt(percent(result["http_req_failed_rate"])),
            fmt(percent(result["dropped_iterations_rate"])),
            fmt(result["container_cpu_ms_per_req"], 3),
            fmt(result["helper_cpu_ms_per_req"], 3),
            fmt(result["http_req_duration_med_ms"], 3),
            fmt(result["http_req_duration_p95_ms"], 3),
            fmt(result["http_req_duration_p99_ms"], 3),
            fmt(result["http_req_duration_max_ms"], 3),
        ]
        for result in results
    ]

    widths = [max(len(str(row[i])) for row in [headers, *rows]) for i in range(len(headers))]
    print()
    print(" ".join(headers[i].ljust(widths[i]) for i in range(len(headers))))
    print(" ".join("-" * width for width in widths))
    for row in rows:
        print(" ".join(str(row[i]).ljust(widths[i]) for i in range(len(row))))


def cpu_ms_per_request(cpu_pct: float | None, requests_per_second: float | None) -> float | None:
    if cpu_pct is None or requests_per_second is None or requests_per_second == 0:
        return None
    return (cpu_pct / 100.0) * 1000.0 / requests_per_second


def percent(rate: float | None) -> float | None:
    return rate * 100.0 if rate is not None else None


MEMORY_METRICS = {
    "container_current_bytes": ("container", "current_bytes"),
    "container_anon_bytes": ("container", "stat", "anon"),
    "container_file_bytes": ("container", "stat", "file"),
    "container_shmem_bytes": ("container", "stat", "shmem"),
    "container_kernel_stack_bytes": ("container", "stat", "kernel_stack"),
    "container_pagetables_bytes": ("container", "stat", "pagetables"),
    "container_slab_bytes": ("container", "stat", "slab"),
    "web_rss_bytes": ("processes", "web", "rss_bytes"),
    "web_pss_bytes": ("processes", "web", "pss_bytes"),
    "web_uss_bytes": ("processes", "web", "uss_bytes"),
    "web_anonymous_bytes": ("processes", "web", "anonymous_bytes"),
    "web_swap_bytes": ("processes", "web", "swap_bytes"),
    "web_vm_size_bytes": ("processes", "web", "vm_size_bytes"),
    "web_vm_hwm_bytes": ("processes", "web", "vm_hwm_bytes"),
    "web_threads": ("processes", "web", "threads"),
    "web_fds": ("processes", "web", "fds"),
    "helper_rss_bytes": ("processes", "helper", "rss_bytes"),
    "helper_pss_bytes": ("processes", "helper", "pss_bytes"),
    "helper_uss_bytes": ("processes", "helper", "uss_bytes"),
    "helper_anonymous_bytes": ("processes", "helper", "anonymous_bytes"),
    "helper_swap_bytes": ("processes", "helper", "swap_bytes"),
    "helper_vm_size_bytes": ("processes", "helper", "vm_size_bytes"),
    "helper_vm_hwm_bytes": ("processes", "helper", "vm_hwm_bytes"),
    "helper_threads": ("processes", "helper", "threads"),
    "helper_fds": ("processes", "helper", "fds"),
}

MEMORY_IDENTIFIERS = {
    "helper_present": ("processes", "helper", "present"),
    "helper_pid": ("processes", "helper", "pid"),
    "helper_generation": ("processes", "helper", "generation"),
}


class MemorySampler:
    def __init__(
        self,
        cgroup_dir: Path,
        process_reader: "ProcessMemoryReader",
        output: Path,
        interval: float,
    ) -> None:
        self.cgroup_dir = cgroup_dir
        self.process_reader = process_reader
        self.output = output
        self.interval = interval
        self.stop_event = threading.Event()
        self.thread: threading.Thread | None = None
        self.error: str | None = None
        self.samples: list[dict[str, Any]] = []
        self.state_lock = threading.Lock()
        self.phase = "setup"
        self.cycle = 0

    def set_phase(self, phase: str, cycle: int) -> None:
        with self.state_lock:
            self.phase = phase
            self.cycle = cycle

    def start(self) -> None:
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.stop_event.set()
        if self.thread is not None:
            self.thread.join(timeout=max(5.0, self.interval + 1.0))
        if self.error is not None:
            raise RuntimeError(f"memory sampler failed: {self.error}")

    def _run(self) -> None:
        self.output.parent.mkdir(parents=True, exist_ok=True)
        headers = [
            "elapsed_s",
            "phase",
            "cycle",
            *MEMORY_IDENTIFIERS,
            *MEMORY_METRICS,
        ]
        start = time.monotonic()
        with self.output.open("w") as file:
            file.write(",".join(headers) + "\n")
            file.flush()
            while True:
                with self.state_lock:
                    phase = self.phase
                    cycle = self.cycle
                try:
                    snapshot = capture_memory_snapshot(
                        self.cgroup_dir,
                        self.process_reader,
                    )
                except Exception as error:
                    self.error = f"{type(error).__name__}: {error}"
                    write_text(self.output.with_suffix(".error"), self.error + "\n")
                    return
                snapshot["elapsed_s"] = time.monotonic() - start
                snapshot["phase"] = phase
                snapshot["cycle"] = cycle
                self.samples.append(snapshot)
                fields = [
                    f"{snapshot['elapsed_s']:.6f}",
                    phase,
                    str(cycle),
                    *[
                        format_csv_number(nested_value(snapshot, path))
                        for path in [
                            *MEMORY_IDENTIFIERS.values(),
                            *MEMORY_METRICS.values(),
                        ]
                    ],
                ]
                file.write(",".join(fields) + "\n")
                file.flush()
                if self.stop_event.wait(self.interval):
                    return


def capture_memory_snapshot(
    cgroup_dir: Path,
    process_reader: "ProcessMemoryReader",
) -> dict[str, Any]:
    return {
        "monotonic_ns": time.monotonic_ns(),
        "container": read_cgroup_memory(cgroup_dir),
        "processes": process_reader.read_all(),
    }


def read_cgroup_memory(cgroup_dir: Path) -> dict[str, Any]:
    memory_stat = read_name_value_file(cgroup_dir / "memory.stat")
    return {
        "current_bytes": read_single_integer_file(cgroup_dir / "memory.current"),
        "peak_bytes": read_single_integer_file(cgroup_dir / "memory.peak"),
        "swap_current_bytes": read_single_integer_file(cgroup_dir / "memory.swap.current"),
        "stat": memory_stat,
        "events": read_name_value_file(cgroup_dir / "memory.events"),
    }


class ProcessMemoryReader:
    def __init__(
        self,
        container_id: str,
        pids: dict[str, str],
        helper_nofile: int,
    ) -> None:
        self.container_id = container_id
        self.pids: dict[str, str | None] = dict(pids)
        self.helper_nofile = helper_nofile
        self.helper_generation = 1
        self.lock = threading.Lock()
        self.transitions: list[dict[str, Any]] = [
            {
                "generation": self.helper_generation,
                "new_pid": pids["helper"],
                "old_pid": None,
                "reason": "initial",
                "timestamp_utc": datetime.now(timezone.utc).isoformat(),
            }
        ]
        try:
            for pid in pids.values():
                read_process_memory(pid)
        except RuntimeError:
            self.mode = "docker-exec"
            self.namespace_pids = {
                role: process_namespace_pid(pid)
                for role, pid in pids.items()
            }
            self.read_all()
        else:
            self.mode = "host-proc"
            self.namespace_pids = {}

    def read_all(self) -> dict[str, dict[str, Any]]:
        with self.lock:
            return self._read_all_locked()

    def _read_all_locked(self) -> dict[str, dict[str, Any]]:
        if self.pids["helper"] is None:
            self._refresh_helper("helper absent at sample")
        try:
            processes = self._read_all_once()
        except RuntimeError as first_error:
            if not self._refresh_helper(f"proc read failed: {first_error}"):
                processes = self._read_all_once(include_helper=False)
            else:
                processes = self._read_all_once()

        helper = processes.get("helper")
        if helper is None:
            processes["helper"] = {
                "pid": None,
                "present": 0,
                "generation": self.helper_generation,
            }
        else:
            helper["present"] = 1
            helper["generation"] = self.helper_generation
        processes["web"]["present"] = 1
        return processes

    def _read_all_once(
        self,
        *,
        include_helper: bool = True,
    ) -> dict[str, dict[str, Any]]:
        if self.mode == "host-proc":
            result = {"web": read_process_memory(require_pid(self.pids["web"], "web"))}
            if include_helper and self.pids["helper"] is not None:
                result["helper"] = read_process_memory(self.pids["helper"])
            return result

        script = """
set -eu
for spec do
    role=${spec%%:*}
    pid=${spec#*:}
    printf '@@BEGIN:%s\\n' "$role"
    printf '@@STATUS\\n'
    cat "/proc/$pid/status"
    printf '@@SMAPS\\n'
    cat "/proc/$pid/smaps_rollup"
    printf '@@STAT\\n'
    cat "/proc/$pid/stat"
    printf '\\n@@FDS\\n'
    find "/proc/$pid/fd" -mindepth 1 -maxdepth 1 -print | wc -l
    printf '@@END\\n'
done
"""
        specs = [
            f"{role}:{self.namespace_pids[role]}"
            for role in self.pids
            if self.pids[role] is not None
            and (role != "helper" or include_helper)
        ]
        command = [
            "docker",
            "exec",
            self.container_id,
            "sh",
            "-c",
            script,
            "memory-reader",
            *specs,
        ]
        result = subprocess.run(
            command,
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            raise RuntimeError(
                f"container proc reader failed with exit {result.returncode}: "
                f"{result.stderr.strip()}"
            )
        raw = result.stdout
        sections = parse_container_process_memory(raw)
        expected = {"web"}
        if include_helper and self.pids["helper"] is not None:
            expected.add("helper")
        missing = sorted(expected - sections.keys())
        if missing:
            raise RuntimeError(
                "container proc reader omitted process roles: " + ", ".join(missing)
            )
        return {
            role: process_memory_from_text(
                require_pid(self.pids[role], role),
                section["status"],
                section["smaps"],
                section["stat"],
                section["fds"],
            )
            for role, section in sections.items()
        }

    def _refresh_helper(self, reason: str) -> bool:
        result = subprocess.run(
            ["docker", "top", self.container_id, "-eo", "pid,comm,args"],
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            raise RuntimeError(
                f"cannot refresh helper process list: {result.stderr.strip()}"
            )
        new_pid = parse_helper_pid(result.stdout)
        old_pid = self.pids["helper"]
        if new_pid == old_pid:
            return new_pid is not None

        self.pids["helper"] = new_pid
        if new_pid is not None:
            require_helper_nofile(new_pid, self.helper_nofile)
            self.namespace_pids["helper"] = process_namespace_pid(new_pid)
            self.helper_generation += 1
        else:
            self.namespace_pids.pop("helper", None)
        self.transitions.append(
            {
                "generation": self.helper_generation,
                "new_pid": new_pid,
                "old_pid": old_pid,
                "reason": reason,
                "timestamp_utc": datetime.now(timezone.utc).isoformat(),
            }
        )
        return new_pid is not None

    def write_full_snapshot(self, root: Path) -> None:
        root.mkdir(parents=True, exist_ok=True)
        with self.lock:
            if self.pids["helper"] is None:
                self._refresh_helper("full snapshot")
            self._write_full_snapshot_locked(root)

    def _write_full_snapshot_locked(self, root: Path) -> None:
        for role, host_pid in self.pids.items():
            if host_pid is None:
                write_text(root / f"{role}.absent", "process not running\n")
                continue
            for source in ("status", "smaps_rollup", "smaps", "maps"):
                destination = root / f"{role}.{source}"
                if self.mode == "host-proc":
                    path = Path("/proc") / host_pid / source
                    try:
                        write_text(destination, path.read_text())
                    except OSError as error:
                        write_text(destination.with_suffix(f".{source}.error"), f"{error}\n")
                    continue

                namespace_pid = self.namespace_pids[role]
                result = subprocess.run(
                    [
                        "docker",
                        "exec",
                        self.container_id,
                        "cat",
                        f"/proc/{namespace_pid}/{source}",
                    ],
                    capture_output=True,
                    text=True,
                    check=False,
                )
                if result.returncode == 0:
                    write_text(destination, result.stdout)
                else:
                    write_text(
                        destination.with_suffix(f".{source}.error"),
                        result.stderr,
                    )


class AggregateProcessMemoryReader:
    """Collect aggregate memory for all web and AppSec helper processes."""

    SUM_FIELDS = (
        "rss_bytes",
        "pss_bytes",
        "uss_bytes",
        "private_clean_bytes",
        "private_dirty_bytes",
        "shared_clean_bytes",
        "shared_dirty_bytes",
        "anonymous_bytes",
        "file_rss_bytes",
        "shmem_rss_bytes",
        "swap_bytes",
        "lazy_free_bytes",
        "vm_size_bytes",
        "vm_peak_bytes",
        "vm_hwm_bytes",
        "threads",
        "fds",
        "minor_faults",
        "major_faults",
    )

    def __init__(self, container_id: str, helper_nofile: int) -> None:
        self.container_id = container_id
        self.helper_nofile = helper_nofile
        self.mode = "docker-exec-aggregate"
        self.helper_generation = 1
        self.lock = threading.Lock()
        initial = self._discover_processes()
        helper_pids = initial["helper"]
        if not helper_pids:
            raise RuntimeError("no AppSec helper process found for aggregate memory capture")
        self.helper_pids = tuple(helper_pids)
        self._verify_helper_limits(helper_pids)
        self.transitions: list[dict[str, Any]] = [
            {
                "generation": self.helper_generation,
                "new_pids": helper_pids,
                "old_pids": [],
                "reason": "initial",
                "timestamp_utc": datetime.now(timezone.utc).isoformat(),
            }
        ]
        self.read_all()

    def read_all(self) -> dict[str, dict[str, Any]]:
        with self.lock:
            last_error: RuntimeError | None = None
            for _ in range(3):
                process_pids = self._discover_processes()
                self._record_helper_transition(process_pids["helper"], "process set changed")
                try:
                    return self._read_process_set(process_pids)
                except RuntimeError as error:
                    last_error = error
            raise RuntimeError(f"could not capture stable container process set: {last_error}")

    def _discover_processes(self) -> dict[str, list[str]]:
        result = subprocess.run(
            ["docker", "top", self.container_id, "-eo", "pid,comm,args"],
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            raise RuntimeError(f"cannot list container processes: {result.stderr.strip()}")

        processes = {"web": [], "helper": []}
        for line in result.stdout.splitlines()[1:]:
            fields = line.split(maxsplit=2)
            if len(fields) < 2 or not fields[0].isdigit():
                continue
            role = (
                "helper"
                if re.search(
                    r"datadog-ipc-helper|dd-ipc-helper|ddappsec-helper|helper-rust",
                    line,
                )
                else "web"
            )
            processes[role].append(fields[0])
        if not processes["web"]:
            raise RuntimeError("container has no web processes")
        return {role: sorted(pids, key=int) for role, pids in processes.items()}

    def _read_process_set(
        self,
        process_pids: dict[str, list[str]],
    ) -> dict[str, dict[str, Any]]:
        labels: dict[str, tuple[str, str]] = {}
        specs: list[str] = []
        for role, pids in process_pids.items():
            for index, host_pid in enumerate(pids):
                label = f"{role}-{index}"
                labels[label] = (role, host_pid)
                specs.append(f"{label}:{process_namespace_pid(host_pid)}")

        script = """
set -eu
for spec do
    label=${spec%%:*}
    pid=${spec#*:}
    printf '@@BEGIN:%s\\n' "$label"
    printf '@@STATUS\\n'
    cat "/proc/$pid/status"
    printf '@@SMAPS\\n'
    cat "/proc/$pid/smaps_rollup"
    printf '@@STAT\\n'
    cat "/proc/$pid/stat"
    printf '\\n@@FDS\\n'
    find "/proc/$pid/fd" -mindepth 1 -maxdepth 1 -print | wc -l
    printf '@@END\\n'
done
"""
        result = subprocess.run(
            [
                "docker",
                "exec",
                self.container_id,
                "sh",
                "-c",
                script,
                "memory-reader",
                *specs,
            ],
            capture_output=True,
            text=True,
            check=False,
        )
        if result.returncode != 0:
            raise RuntimeError(
                f"container aggregate proc reader failed with exit "
                f"{result.returncode}: {result.stderr.strip()}"
            )
        sections = parse_container_process_memory(result.stdout)
        missing = sorted(labels.keys() - sections.keys())
        if missing:
            raise RuntimeError(
                "container aggregate proc reader omitted processes: "
                + ", ".join(missing)
            )

        by_role: dict[str, list[dict[str, Any]]] = {"web": [], "helper": []}
        for label, section in sections.items():
            role, host_pid = labels[label]
            by_role[role].append(
                process_memory_from_text(
                    host_pid,
                    section["status"],
                    section["smaps"],
                    section["stat"],
                    section["fds"],
                )
            )
        return {
            "web": self._aggregate_role(by_role["web"], generation=None),
            "helper": self._aggregate_role(
                by_role["helper"],
                generation=self.helper_generation,
            ),
        }

    def _aggregate_role(
        self,
        processes: list[dict[str, Any]],
        *,
        generation: int | None,
    ) -> dict[str, Any]:
        aggregate: dict[str, Any] = {
            field: sum(int(process.get(field, 0)) for process in processes)
            for field in self.SUM_FIELDS
        }
        pids = sorted((int(process["pid"]) for process in processes))
        aggregate.update(
            {
                "pid": pids[0] if pids else None,
                "pids": pids,
                "process_count": len(pids),
                "present": int(bool(pids)),
            }
        )
        if generation is not None:
            aggregate["generation"] = generation
        return aggregate

    def _record_helper_transition(self, helper_pids: list[str], reason: str) -> None:
        new_pids = tuple(helper_pids)
        if new_pids == self.helper_pids:
            return
        old_pids = self.helper_pids
        self.helper_pids = new_pids
        self.helper_generation += 1
        self._verify_helper_limits(helper_pids)
        self.transitions.append(
            {
                "generation": self.helper_generation,
                "new_pids": list(new_pids),
                "old_pids": list(old_pids),
                "reason": reason,
                "timestamp_utc": datetime.now(timezone.utc).isoformat(),
            }
        )

    def _verify_helper_limits(self, helper_pids: list[str]) -> None:
        for helper_pid in helper_pids:
            require_helper_nofile(helper_pid, self.helper_nofile)

    def write_full_snapshot(self, root: Path) -> None:
        root.mkdir(parents=True, exist_ok=True)
        with self.lock:
            process_pids = self._discover_processes()
            self._record_helper_transition(process_pids["helper"], "full snapshot")
            write_text(
                root / "processes.json",
                json.dumps(process_pids, indent=2, sort_keys=True) + "\n",
            )
            for role, pids in process_pids.items():
                for index, host_pid in enumerate(pids):
                    namespace_pid = process_namespace_pid(host_pid)
                    for source in ("status", "smaps_rollup", "smaps", "maps"):
                        destination = root / f"{role}-{index:02d}-{host_pid}.{source}"
                        result = subprocess.run(
                            [
                                "docker",
                                "exec",
                                self.container_id,
                                "cat",
                                f"/proc/{namespace_pid}/{source}",
                            ],
                            capture_output=True,
                            text=True,
                            check=False,
                        )
                        if result.returncode == 0:
                            write_text(destination, result.stdout)
                        else:
                            write_text(
                                destination.with_suffix(f".{source}.error"),
                                result.stderr,
                            )


def require_pid(pid: str | None, role: str) -> str:
    if pid is None:
        raise RuntimeError(f"{role} process is not running")
    return pid


def process_namespace_pid(host_pid: str) -> str:
    try:
        status = Path("/proc").joinpath(host_pid, "status").read_text()
    except OSError as error:
        raise RuntimeError(
            f"cannot read namespace metadata for host pid {host_pid}: {error}"
        ) from error
    for line in status.splitlines():
        if line.startswith("NSpid:"):
            fields = line.split()[1:]
            if fields:
                return fields[-1]
    raise RuntimeError(f"NSpid is missing from /proc/{host_pid}/status")


def parse_container_process_memory(raw: str) -> dict[str, dict[str, str]]:
    result: dict[str, dict[str, str]] = {}
    role: str | None = None
    section: str | None = None
    lines: list[str] = []

    def flush() -> None:
        if role is not None and section is not None:
            result.setdefault(role, {})[section] = "\n".join(lines) + "\n"

    for line in raw.splitlines():
        if line.startswith("@@BEGIN:"):
            flush()
            role = line.removeprefix("@@BEGIN:")
            section = None
            lines = []
        elif line == "@@END":
            flush()
            role = None
            section = None
            lines = []
        elif line in {"@@STATUS", "@@SMAPS", "@@STAT", "@@FDS"}:
            flush()
            section = line.removeprefix("@@").lower()
            lines = []
        elif role is not None and section is not None:
            lines.append(line)
    flush()
    return result


def read_process_memory(pid: str) -> dict[str, Any]:
    proc_dir = Path("/proc") / pid
    try:
        status_text = proc_dir.joinpath("status").read_text()
        smaps_text = proc_dir.joinpath("smaps_rollup").read_text()
        stat_text = proc_dir.joinpath("stat").read_text()
        fds_text = str(
            sum(1 for entry in proc_dir.joinpath("fd").iterdir() if entry.name.isdigit())
        )
    except OSError as error:
        raise RuntimeError(f"cannot collect complete memory data for pid {pid}: {error}") from error
    return process_memory_from_text(pid, status_text, smaps_text, stat_text, fds_text)


def process_memory_from_text(
    pid: str,
    status_text: str,
    smaps_text: str,
    stat_text: str,
    fds_text: str,
) -> dict[str, Any]:
    status = parse_proc_kv_text(status_text)
    smaps = parse_proc_kv_text(smaps_text)
    required_smaps_fields = {"Rss", "Pss", "Private_Clean", "Private_Dirty", "Anonymous"}
    missing = sorted(required_smaps_fields - smaps.keys())
    if missing:
        raise RuntimeError(
            f"smaps_rollup for pid {pid} is missing required fields: {', '.join(missing)}"
        )
    private_clean = smaps.get("Private_Clean", 0)
    private_dirty = smaps.get("Private_Dirty", 0)
    rss = smaps.get("Rss", status.get("VmRSS", 0))
    try:
        stat_fields = stat_text.rsplit(")", 1)[1].split()
        fds = int(fds_text.strip())
    except (IndexError, ValueError) as error:
        raise RuntimeError(f"invalid proc data for pid {pid}: {error}") from error
    return {
        "pid": int(pid),
        "rss_bytes": rss,
        "pss_bytes": smaps.get("Pss", 0),
        "uss_bytes": private_clean + private_dirty,
        "private_clean_bytes": private_clean,
        "private_dirty_bytes": private_dirty,
        "shared_clean_bytes": smaps.get("Shared_Clean", 0),
        "shared_dirty_bytes": smaps.get("Shared_Dirty", 0),
        "anonymous_bytes": smaps.get("Anonymous", status.get("RssAnon", 0)),
        "file_rss_bytes": status.get("RssFile", 0),
        "shmem_rss_bytes": status.get("RssShmem", 0),
        "swap_bytes": smaps.get("Swap", status.get("VmSwap", 0)),
        "lazy_free_bytes": smaps.get("LazyFree", 0),
        "vm_size_bytes": status.get("VmSize", 0),
        "vm_peak_bytes": status.get("VmPeak", 0),
        "vm_hwm_bytes": status.get("VmHWM", 0),
        "threads": status.get("Threads", 0),
        "fds": fds,
        "minor_faults": int(stat_fields[7]) if len(stat_fields) > 9 else 0,
        "major_faults": int(stat_fields[9]) if len(stat_fields) > 9 else 0,
    }


def read_proc_kv_file(path: Path) -> dict[str, int]:
    try:
        text = path.read_text()
    except OSError:
        return {}
    return parse_proc_kv_text(text)


def parse_proc_kv_text(text: str) -> dict[str, int]:
    result: dict[str, int] = {}
    for line in text.splitlines():
        if ":" not in line:
            continue
        name, raw_value = line.split(":", 1)
        fields = raw_value.split()
        if not fields:
            continue
        try:
            value = int(fields[0])
        except ValueError:
            continue
        if len(fields) > 1 and fields[1] == "kB":
            value *= 1024
        result[name] = value
    return result


def read_name_value_file(path: Path) -> dict[str, int]:
    result: dict[str, int] = {}
    try:
        lines = path.read_text().splitlines()
    except OSError:
        return result
    for line in lines:
        fields = line.split()
        if len(fields) != 2:
            continue
        try:
            result[fields[0]] = int(fields[1])
        except ValueError:
            pass
    return result


def read_single_integer_file(path: Path) -> int | None:
    try:
        return int(path.read_text().strip())
    except (OSError, ValueError):
        return None


def analyze_memory_profile(
    phase_snapshots: list[dict[str, Any]],
    samples: list[dict[str, Any]],
) -> dict[str, Any]:
    post_idle = [
        snapshot
        for snapshot in phase_snapshots
        if snapshot.get("label") == "baseline_after_idle"
        or str(snapshot.get("label", "")).endswith("_after_idle")
    ]
    metrics: dict[str, Any] = {}
    for name, path in MEMORY_METRICS.items():
        values = [
            (
                float(snapshot["cumulative_requests"]),
                float(nested_value(snapshot, path) or 0),
            )
            for snapshot in post_idle
        ]
        slope = theil_sen_slope(values)
        post_warmup_values = values[1:]
        tail_values = values[2:]
        post_warmup_slope = theil_sen_slope(post_warmup_values)
        tail_slope = theil_sen_slope(tail_values)
        first = values[0][1] if values else None
        last = values[-1][1] if values else None
        post_warmup_first = (
            post_warmup_values[0][1]
            if post_warmup_values
            else None
        )
        sample_values = [
            float(value)
            for sample in samples
            if (value := nested_value(sample, path)) is not None
        ]
        metrics[name] = {
            "baseline": first,
            "final_post_idle": last,
            "retained_delta": last - first if first is not None and last is not None else None,
            "post_idle_slope_per_request": slope,
            "post_idle_slope_per_million_requests": (
                slope * 1_000_000 if slope is not None else None
            ),
            "post_warmup_retained_delta": (
                last - post_warmup_first
                if last is not None and post_warmup_first is not None
                else None
            ),
            "post_warmup_slope_per_request": post_warmup_slope,
            "post_warmup_slope_per_million_requests": (
                post_warmup_slope * 1_000_000
                if post_warmup_slope is not None
                else None
            ),
            "tail_slope_per_request": tail_slope,
            "tail_slope_per_million_requests": (
                tail_slope * 1_000_000
                if tail_slope is not None
                else None
            ),
            "sample_min": min(sample_values) if sample_values else None,
            "sample_max": max(sample_values) if sample_values else None,
        }

    phase_summary: dict[str, Any] = {}
    grouped: dict[tuple[str, int], list[dict[str, Any]]] = defaultdict(list)
    for sample in samples:
        grouped[(str(sample.get("phase")), int(sample.get("cycle", 0)))].append(sample)
    for (phase, cycle), group in sorted(grouped.items(), key=lambda item: (item[0][1], item[0][0])):
        label = f"{cycle:02d}-{phase}"
        phase_summary[label] = {
            name: summarize_numeric_values(
                [
                    float(value)
                    for sample in group
                    if (value := nested_value(sample, path)) is not None
                ]
            )
            for name, path in MEMORY_METRICS.items()
        }

    return {
        "post_idle_points": len(post_idle),
        "post_idle_cumulative_requests": [
            snapshot["cumulative_requests"]
            for snapshot in post_idle
        ],
        "metrics": metrics,
        "phase_summary": phase_summary,
    }


def nested_value(value: dict[str, Any], path: tuple[str, ...]) -> int | float | None:
    current: Any = value
    for part in path:
        if not isinstance(current, dict):
            return None
        current = current.get(part)
    return current if isinstance(current, (int, float)) else None


def format_csv_number(value: int | float | None) -> str:
    return "" if value is None else str(value)


def theil_sen_slope(points: list[tuple[float, float]]) -> float | None:
    slopes = [
        (right_y - left_y) / (right_x - left_x)
        for index, (left_x, left_y) in enumerate(points)
        for right_x, right_y in points[index + 1 :]
        if right_x != left_x
    ]
    return float(median(slopes)) if slopes else None


def summarize_numeric_values(values: list[float]) -> dict[str, float | None]:
    if not values:
        return {"first": None, "last": None, "min": None, "max": None, "median": None}
    return {
        "first": values[0],
        "last": values[-1],
        "min": min(values),
        "max": max(values),
        "median": float(median(values)),
    }


class CpuSampler:
    def __init__(self, cpu_stat: Path | None, container_id: str, output: Path, interval: float) -> None:
        self.cpu_stat = cpu_stat
        self.container_id = container_id
        self.output = output
        self.interval = interval
        self.stop_event = threading.Event()
        self.thread: threading.Thread | None = None
        self.samples: list[float] = []

    def start(self) -> None:
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.stop_event.set()
        if self.thread is not None:
            self.thread.join(timeout=5)

    @property
    def average(self) -> float | None:
        return sum(self.samples) / len(self.samples) if self.samples else None

    def _run(self) -> None:
        if self.cpu_stat is None:
            self._run_docker_stats()
            return

        self.output.parent.mkdir(parents=True, exist_ok=True)
        previous_time = time.monotonic()
        previous_usage = read_usage_usec(self.cpu_stat)  # type: ignore[arg-type]
        with self.output.open("w") as file:
            file.write("elapsed_s,usage_usec,cpu_pct\n")
            file.flush()
            start = previous_time
            while not self.stop_event.wait(self.interval):
                now = time.monotonic()
                usage = read_usage_usec(self.cpu_stat)  # type: ignore[arg-type]
                elapsed_usec = (now - previous_time) * 1_000_000
                cpu_pct = ((usage - previous_usage) / elapsed_usec) * 100.0
                self.samples.append(cpu_pct)
                file.write(f"{now - start:.6f},{usage},{cpu_pct:.3f}\n")
                file.flush()
                previous_time = now
                previous_usage = usage

    def _run_docker_stats(self) -> None:
        self.output.parent.mkdir(parents=True, exist_ok=True)
        start = time.monotonic()
        with self.output.open("w") as file:
            file.write("elapsed_s,cpu_pct\n")
            file.flush()
            while not self.stop_event.wait(self.interval):
                cpu_pct = docker_stats_cpu_pct(self.container_id)
                if cpu_pct is None:
                    continue
                self.samples.append(cpu_pct)
                file.write(f"{time.monotonic() - start:.6f},{cpu_pct:.3f}\n")
                file.flush()


class ProcessCpuSampler:
    def __init__(self, pid: str, output: Path, interval: float) -> None:
        self.pid = pid
        self.output = output
        self.interval = interval
        self.stop_event = threading.Event()
        self.thread: threading.Thread | None = None
        self.samples: list[float] = []
        self.clock_ticks_per_second = os.sysconf(os.sysconf_names["SC_CLK_TCK"])

    def start(self) -> None:
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.stop_event.set()
        if self.thread is not None:
            self.thread.join(timeout=5)

    @property
    def average(self) -> float | None:
        return sum(self.samples) / len(self.samples) if self.samples else None

    def _run(self) -> None:
        self.output.parent.mkdir(parents=True, exist_ok=True)
        previous_time = time.monotonic()
        previous_ticks = read_proc_cpu_ticks(self.pid)
        if previous_ticks is None:
            write_text(self.output, f"could not read /proc/{self.pid}/stat\n")
            return

        with self.output.open("w") as file:
            file.write("elapsed_s,cpu_pct,cpu_ticks\n")
            file.flush()
            start = previous_time
            while not self.stop_event.wait(self.interval):
                now = time.monotonic()
                ticks = read_proc_cpu_ticks(self.pid)
                if ticks is None:
                    break
                elapsed_s = now - previous_time
                cpu_s = (ticks - previous_ticks) / self.clock_ticks_per_second
                cpu_pct = (cpu_s / elapsed_s) * 100.0 if elapsed_s > 0 else 0.0
                self.samples.append(cpu_pct)
                file.write(f"{now - start:.6f},{cpu_pct:.3f},{ticks}\n")
                file.flush()
                previous_time = now
                previous_ticks = ticks


def start_perf(
    args: argparse.Namespace,
    pid: str,
    case_dir: Path,
    output_name: str,
) -> tuple[subprocess.Popen[str] | None, Any]:
    perf_command = shlex.split(args.perf_command)
    if not perf_command or shutil.which(perf_command[0]) is None:
        write_text(case_dir / output_name, "perf not found\n")
        return None, None

    perf_cmd = [
        *perf_command,
        "stat",
        "-x",
        ",",
        "-p",
        pid,
        "-e",
        args.perf_events,
        "--",
        "sleep",
        str(args.duration + args.perf_extra_seconds),
    ]
    perf_env = os.environ.copy()
    perf_env["LC_ALL"] = "C"
    perf_file = (case_dir / output_name).open("w")
    print("+ " + " ".join(shlex.quote(part) for part in perf_cmd), flush=True)
    process = subprocess.Popen(
        perf_cmd,
        stdout=perf_file,
        stderr=subprocess.STDOUT,
        text=True,
        env=perf_env,
    )
    return process, perf_file


def wait_perf(process: subprocess.Popen[str] | None, perf_file: Any, timeout: float) -> None:
    if process is None:
        return
    process.wait(timeout=timeout)
    if perf_file is not None:
        perf_file.close()


def stop_perf(process: subprocess.Popen[str] | None, perf_file: Any) -> None:
    if process is not None and process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
    if perf_file is not None and not perf_file.closed:
        perf_file.close()


def wait_for_helper_process(container_id: str, timeout: float) -> tuple[str, str]:
    deadline = time.monotonic() + timeout
    docker_top = ""
    while True:
        docker_top = capture(["docker", "top", container_id, "-eo", "pid,comm,args"])
        helper_pid = parse_helper_pid(docker_top)
        if helper_pid is not None:
            return docker_top, helper_pid
        if time.monotonic() >= deadline:
            raise RuntimeError(f"helper process not found within {timeout:.1f}s:\n{docker_top}")
        time.sleep(0.1)


def wait_for_single_helper_process(
    container_id: str,
    timeout: float,
) -> tuple[str, str]:
    deadline = time.monotonic() + timeout
    docker_top = ""
    helper_pids: list[str] = []
    while True:
        docker_top = capture(
            ["docker", "top", container_id, "-eo", "pid,comm,args"]
        )
        helper_pids = parse_helper_pids(docker_top)
        if len(helper_pids) == 1:
            return docker_top, helper_pids[0]
        if time.monotonic() >= deadline:
            raise RuntimeError(
                "expected exactly one helper process within "
                f"{timeout:.1f}s, found {len(helper_pids)} "
                f"({', '.join(helper_pids) or 'none'}):\n{docker_top}"
            )
        time.sleep(0.1)


def parse_helper_pid(docker_top: str) -> str | None:
    helper_pids = parse_helper_pids(docker_top)
    return helper_pids[0] if helper_pids else None


def parse_helper_pids(docker_top: str) -> list[str]:
    helper_pids: list[str] = []
    for line in docker_top.splitlines():
        if re.search(r"datadog-ipc-helper|dd-ipc-helper|ddappsec-helper|helper-rust", line):
            fields = line.split()
            if fields and fields[0].isdigit():
                helper_pids.append(fields[0])
    return helper_pids


def require_helper_nofile(helper_pid: str, minimum: int) -> str:
    limits_path = Path(f"/proc/{helper_pid}/limits")
    try:
        limits = limits_path.read_text()
    except OSError as error:
        raise RuntimeError(f"could not read helper limits from {limits_path}: {error}") from error

    match = re.search(r"^Max open files\s+(\d+|unlimited)\s+", limits, re.MULTILINE)
    if match is None:
        raise RuntimeError(f"Max open files is missing from {limits_path}")

    soft_text = match.group(1)
    if soft_text != "unlimited" and int(soft_text) < minimum:
        raise RuntimeError(
            f"helper RLIMIT_NOFILE is {soft_text}; expected at least {minimum}"
        )

    print(f"Verified helper RLIMIT_NOFILE >= {minimum} (soft limit: {soft_text})", flush=True)
    return limits


def write_proc_maps(helper_pid: str, case_dir: Path) -> None:
    try:
        write_text(case_dir / "helper.maps", Path(f"/proc/{helper_pid}/maps").read_text())
    except OSError as error:
        write_text(case_dir / "helper.maps.error", f"{error}\n")


def capture_boundary_snapshot(
    cpu_stat: Path | None,
    web_pid: str,
    helper_pid: str,
    *,
    scheduler_first: bool,
) -> BoundarySnapshot:
    scheduler_by_role = (
        {
            "helper": read_process_scheduler_counters(helper_pid),
            "web": read_process_scheduler_counters(web_pid),
        }
        if scheduler_first
        else {}
    )
    monotonic_ns = time.monotonic_ns()
    container_usage_usec = None
    if cpu_stat is not None:
        try:
            container_usage_usec = read_usage_usec(cpu_stat)
        except (OSError, RuntimeError, ValueError):
            pass

    helper_cpu_ticks = read_proc_cpu_ticks(helper_pid)
    if not scheduler_first:
        scheduler_by_role = {
            "helper": read_process_scheduler_counters(helper_pid),
            "web": read_process_scheduler_counters(web_pid),
        }

    return BoundarySnapshot(
        monotonic_ns=monotonic_ns,
        container_usage_usec=container_usage_usec,
        helper_cpu_ticks=helper_cpu_ticks,
        scheduler_by_role=scheduler_by_role,
    )


def read_process_scheduler_counters(pid: str) -> dict[str, int]:
    fields = {
        "context_switches": 0,
        "involuntary_context_switches": 0,
        "migrations": 0,
        "runqueue_wait_ns": 0,
        "runtime_ns": 0,
        "threads_read": 0,
        "timeslices": 0,
        "voluntary_context_switches": 0,
    }
    task_root = Path("/proc") / pid / "task"
    try:
        tasks = sorted(path for path in task_root.iterdir() if path.name.isdigit())
    except OSError:
        return {**fields, "thread_count": 0}

    for task in tasks:
        read_any = False
        try:
            status = task.joinpath("status").read_text()
            voluntary = parse_proc_status_counter(status, "voluntary_ctxt_switches")
            involuntary = parse_proc_status_counter(status, "nonvoluntary_ctxt_switches")
            fields["voluntary_context_switches"] += voluntary
            fields["involuntary_context_switches"] += involuntary
            fields["context_switches"] += voluntary + involuntary
            read_any = True
        except OSError:
            pass

        try:
            schedstat = task.joinpath("schedstat").read_text().split()
            fields["runtime_ns"] += int(schedstat[0])
            fields["runqueue_wait_ns"] += int(schedstat[1])
            fields["timeslices"] += int(schedstat[2])
            read_any = True
        except (IndexError, OSError, ValueError):
            pass

        try:
            sched = task.joinpath("sched").read_text()
            match = re.search(r"^\s*se\.nr_migrations\s*:\s*(\d+)\s*$", sched, re.MULTILINE)
            if match is not None:
                fields["migrations"] += int(match.group(1))
                read_any = True
        except OSError:
            pass

        if read_any:
            fields["threads_read"] += 1

    return {**fields, "thread_count": len(tasks)}


def parse_proc_status_counter(status: str, name: str) -> int:
    match = re.search(rf"^{re.escape(name)}:\s*(\d+)\s*$", status, re.MULTILINE)
    return int(match.group(1)) if match is not None else 0


def summarize_measurement_window(
    window: MeasurementWindow,
    requests: float | None,
) -> dict[str, Any]:
    elapsed_s = (window.after.monotonic_ns - window.before.monotonic_ns) / 1_000_000_000
    request_count = requests if requests is not None and requests > 0 else None
    container = summarize_cpu_delta(
        window.before.container_usage_usec,
        window.after.container_usage_usec,
        unit_seconds=1 / 1_000_000,
        elapsed_s=elapsed_s,
        requests=request_count,
        counter_name="usage_usec",
    )
    helper = summarize_cpu_delta(
        window.before.helper_cpu_ticks,
        window.after.helper_cpu_ticks,
        unit_seconds=1 / os.sysconf(os.sysconf_names["SC_CLK_TCK"]),
        elapsed_s=elapsed_s,
        requests=request_count,
        counter_name="cpu_ticks",
    )

    scheduler: dict[str, Any] = {}
    for role in sorted(window.before.scheduler_by_role.keys() | window.after.scheduler_by_role.keys()):
        before = window.before.scheduler_by_role.get(role, {})
        after = window.after.scheduler_by_role.get(role, {})
        delta = {
            key: after[key] - before[key]
            for key in (
                "context_switches",
                "involuntary_context_switches",
                "migrations",
                "runqueue_wait_ns",
                "runtime_ns",
                "timeslices",
                "voluntary_context_switches",
            )
            if key in before and key in after
        }
        scheduler[role] = {
            "before": before,
            "after": after,
            "delta": delta,
            "per_request": {
                key: value / request_count if request_count is not None else None
                for key, value in delta.items()
            },
            "counter_reset_or_thread_exit_detected": any(value < 0 for value in delta.values()),
            "thread_count_changed": before.get("thread_count") != after.get("thread_count"),
            "may_undercount_exited_threads": (
                after.get("thread_count", 0) < before.get("thread_count", 0)
            ),
        }

    return {
        "elapsed_s": elapsed_s,
        "requests": requests,
        "cpu": {
            "container": container,
            "helper": helper,
        },
        "scheduler": scheduler,
    }


def summarize_cpu_delta(
    before: int | None,
    after: int | None,
    *,
    unit_seconds: float,
    elapsed_s: float,
    requests: float | None,
    counter_name: str,
) -> dict[str, Any]:
    delta = after - before if before is not None and after is not None else None
    if delta is not None and delta < 0:
        delta = None
    cpu_s = delta * unit_seconds if delta is not None else None
    return {
        f"before_{counter_name}": before,
        f"after_{counter_name}": after,
        f"delta_{counter_name}": delta,
        "cpu_pct": cpu_s / elapsed_s * 100 if cpu_s is not None and elapsed_s > 0 else None,
        "cpu_ms_per_request": (
            cpu_s * 1000 / requests
            if cpu_s is not None and requests is not None and requests > 0
            else None
        ),
    }


def container_cgroup_cpu_stat(container_pid: str) -> Path | None:
    cgroup_dir = container_cgroup_dir(container_pid)
    if cgroup_dir is None:
        return None
    cpu_stat = cgroup_dir / "cpu.stat"
    return cpu_stat if cpu_stat.exists() else None


def container_cgroup_dir(container_pid: str) -> Path | None:
    cgroup_file = Path("/proc") / container_pid / "cgroup"
    try:
        lines = cgroup_file.read_text().splitlines()
    except OSError:
        return None

    for line in lines:
        fields = line.split(":", 2)
        if len(fields) == 3:
            candidate = Path("/sys/fs/cgroup") / fields[2].lstrip("/")
            if candidate.exists():
                return candidate
    return None


def read_usage_usec(cpu_stat: Path) -> int:
    for line in cpu_stat.read_text().splitlines():
        key, value = line.split()
        if key == "usage_usec":
            return int(value)
    raise RuntimeError(f"usage_usec not found in {cpu_stat}")


def read_proc_cpu_ticks(pid: str) -> int | None:
    try:
        stat = Path("/proc") / pid / "stat"
        fields_after_comm = stat.read_text().rsplit(")", 1)[1].split()
        return int(fields_after_comm[11]) + int(fields_after_comm[12])
    except (IndexError, OSError, ValueError):
        return None


def docker_stats_cpu_pct(container_id: str) -> float | None:
    result = subprocess.run(
        ["docker", "stats", "--no-stream", "--format", "{{.CPUPerc}}", container_id],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        return None

    value = result.stdout.strip().removesuffix("%")
    try:
        return float(value)
    except ValueError:
        return None


def parse_perf_task_clock(case_dir: Path) -> tuple[float | None, float | None]:
    task_clock_ms: float | None = None
    elapsed_s: float | None = None
    perf_path = case_dir / "perf-stat.csv"
    if not perf_path.exists():
        return None, None

    for line in perf_path.read_text(errors="replace").splitlines():
        fields = [field.strip() for field in line.split(",")]
        if "seconds time elapsed" in line:
            match = re.search(r"([0-9]+(?:[.,][0-9]+)?)", line)
            if match:
                elapsed_s = float(match.group(1).replace(",", "."))
            continue

        if len(fields) >= 4 and fields[2] == "msec":
            value_text = f"{fields[0]}.{fields[1]}"
            unit = fields[2]
            event = fields[3]
        elif len(fields) >= 3:
            value_text = fields[0]
            unit = fields[1]
            event = fields[2]
        else:
            continue

        try:
            value = float(value_text)
        except ValueError:
            continue

        if event == "task-clock":
            task_clock_ms = value if unit == "msec" else value * 1000.0
    return task_clock_ms, elapsed_s


def metric_value(summary: dict[str, Any], name: str, value: str) -> float | None:
    metric = summary.get("metrics", {}).get(name)
    if not isinstance(metric, dict):
        return None
    values = metric.get("values", metric)
    if not isinstance(values, dict):
        return None
    raw = values.get(value)
    return float(raw) if raw is not None else None


def wait_ready(runtime: Runtime, timeout: float) -> None:
    url = f"http://127.0.0.1:{runtime.port}/"
    deadline = time.monotonic() + timeout
    while True:
        ready = subprocess.run(
            ["curl", "-fsS", url],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        ).returncode == 0
        if ready:
            return
        if time.monotonic() > deadline:
            raise RuntimeError(f"web container did not become ready at {url}")
        time.sleep(0.2)


def compose(runtime: Runtime, args: list[str], *, check: bool = True) -> subprocess.CompletedProcess[str]:
    return run(compose_cmd(runtime, args), cwd=runtime.root, env=runtime.compose_env, check=check)


def compose_cmd(runtime: Runtime, args: list[str]) -> list[str]:
    command = ["docker", "compose"]
    for compose_file in runtime.compose_files:
        command.extend(["-f", str(compose_file)])
    return [*command, "-p", runtime.project, *args]


def ensure_command(name: str) -> None:
    if shutil.which(name) is None:
        raise SystemExit(f"required command not found: {name}")


def ensure_docker_image(image: str) -> None:
    result = run(
        ["docker", "image", "inspect", image],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    if result.returncode != 0:
        run(["docker", "pull", image])


def find_free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def timestamp() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")


def write_text(path: Path, contents: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(contents)


def capture(
    cmd: list[str],
    *,
    cwd: Path | None = None,
    env: dict[str, str] | None = None,
) -> str:
    result = run(cmd, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return result.stdout.strip()


def run(
    cmd: list[str],
    *,
    cwd: Path | None = None,
    env: dict[str, str] | None = None,
    stdin: str | None = None,
    stdout: int | Any | None = None,
    stderr: int | Any | None = None,
    check: bool = True,
) -> subprocess.CompletedProcess[str]:
    print("+ " + " ".join(shlex.quote(part) for part in cmd), flush=True)
    return subprocess.run(
        cmd,
        cwd=cwd,
        env=env,
        input=stdin,
        text=True,
        stdout=stdout,
        stderr=stderr,
        check=check,
    )


if __name__ == "__main__":
    raise SystemExit(main())
