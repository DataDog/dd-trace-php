#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# ///
"""Run k6 against the AppSec benchmark example and report throughput and CPU."""

from __future__ import annotations

import argparse
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
        compose_files=[root / "docker-compose.yml"],
    )

    for command in ["docker", "curl"]:
        ensure_command(command)
    ensure_docker_image("grafana/k6")

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
        run_warmup(runtime, args)
        container_id = capture(compose_cmd(runtime, ["ps", "-q", "web"]), cwd=runtime.root, env=runtime.compose_env)
        if not container_id:
            raise RuntimeError("could not resolve the web container id")

        for mode in cases:
            if mode == "flamegraph":
                results.append(run_flamegraph_case(args, runtime, container_id))
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
    parser.add_argument("--cases", default="fixed1000,saturated", help="Comma-separated cases: fixed1000,saturated,flamegraph.")
    parser.add_argument("--fixed-rps", type=int, default=1000)
    parser.add_argument("--fixed-preallocated-vus", type=int, default=100)
    parser.add_argument("--fixed-max-vus", type=int, default=1000)
    parser.add_argument("--saturated-vus", type=int, default=128)
    parser.add_argument("--duration", type=int, default=25, help="k6 case duration in seconds.")
    parser.add_argument("--warmup-requests", type=int, default=500, help="Requests to send before measured cases.")
    parser.add_argument("--warmup-vus", type=int, default=16, help="Warmup virtual users.")
    parser.add_argument("--ready-timeout", type=float, default=30.0)
    parser.add_argument("--perf-command", default="perf", help="Perf command, optionally with a prefix such as 'sudo -n perf'.")
    parser.add_argument("--perf-events", default=PERF_EVENTS)
    parser.add_argument("--perf-warmup-seconds", type=float, default=1.0)
    parser.add_argument("--perf-extra-seconds", type=int, default=5)
    parser.add_argument("--cpu-sample-interval", type=float, default=0.5)
    parser.add_argument("--web-env", action="append", default=[], metavar="KEY=VALUE", help="Extra web container environment variable.")
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
    valid = {"fixed1000", "saturated", "flamegraph"}
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

    inspect = json.loads(capture(["docker", "inspect", container_id]))
    write_text(case_dir / "docker-inspect.json", json.dumps(inspect, indent=2))
    container_pid = str(inspect[0]["State"]["Pid"])

    docker_top = capture(["docker", "top", container_id, "-eo", "pid,comm,args"])
    write_text(case_dir / "docker-top.txt", docker_top + "\n")
    helper_pid = parse_helper_pid(docker_top)
    write_text(case_dir / "helper.pid", helper_pid + "\n")
    write_proc_maps(helper_pid, case_dir)

    cpu_stat = container_cgroup_cpu_stat(container_pid)
    if cpu_stat is not None:
        write_text(case_dir / "cgroup-cpu-stat-path.txt", str(cpu_stat) + "\n")
    sampler = CpuSampler(cpu_stat, container_id, case_dir / "cpu-samples.csv", args.cpu_sample_interval)
    helper_sampler = ProcessCpuSampler(helper_pid, case_dir / "helper-cpu-samples.csv", args.cpu_sample_interval)

    perf_handles = [
        start_perf(args, helper_pid, case_dir, "perf-stat.csv"),
        start_perf(args, container_pid, case_dir, "frankenphp-perf-stat.csv"),
    ]
    try:
        time.sleep(args.perf_warmup_seconds)
        sampler.start()
        helper_sampler.start()
        run_k6(case_dir, k6_script(mode, runtime.port, args))
        sampler.stop()
        helper_sampler.stop()
        for perf_process, perf_file in perf_handles:
            wait_perf(perf_process, perf_file, args.duration + args.perf_extra_seconds + 10)
        write_text(case_dir / "docker.log", capture(["docker", "logs", container_id]) + "\n")
        return summarize_case(case_dir, sampler, helper_sampler, args)
    finally:
        sampler.stop()
        helper_sampler.stop()
        for perf_process, perf_file in perf_handles:
            stop_perf(perf_process, perf_file)


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

    inspect = json.loads(capture(["docker", "inspect", container_id]))
    write_text(case_dir / "docker-inspect.json", json.dumps(inspect, indent=2))
    container_pid = str(inspect[0]["State"]["Pid"])

    docker_top = capture(["docker", "top", container_id, "-eo", "pid,comm,args"])
    write_text(case_dir / "docker-top.txt", docker_top + "\n")
    helper_pid = parse_helper_pid(docker_top)
    write_text(case_dir / "helper.pid", helper_pid + "\n")
    write_proc_maps(helper_pid, case_dir)

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
        time.sleep(max(args.perf_warmup_seconds, 1.0))
        sampler.start()
        helper_sampler.start()
        run_k6(case_dir, k6_script("flamegraph", runtime.port, args))
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

    write_text(case_dir / "docker.log", capture(["docker", "logs", container_id]) + "\n")
    symbols = extract_flamegraph_symbols(case_dir, container_id)
    flamegraph = build_flamegraph_artifacts(case_dir, symbols, include_roles, helper_pids={helper_pid})
    render_flamegraphs(case_dir, args)
    flamegraph = json.loads((case_dir / "flamegraph-summary.json").read_text())

    result = summarize_case(case_dir, sampler, helper_sampler, args)
    result.update(
        {
            "flamegraph_samples": flamegraph["samples_by_role"],
            "flamegraph_folded": flamegraph["folded_files"],
            "flamegraph_svg": flamegraph["svg_files"],
            "flamegraph_unresolved_frames": flamegraph["unresolved_frames_by_mapping"],
        }
    )
    if autodiscovery is not None:
        result["flamegraph_autodiscovery"] = autodiscovery
    write_text(case_dir / "summary.json", json.dumps(result, indent=2, sort_keys=True))
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
    args: argparse.Namespace,
) -> dict[str, Any]:
    summary_path = case_dir / "k6-summary.json"
    k6_summary = json.loads(summary_path.read_text()) if summary_path.exists() else {}
    task_clock_ms, perf_elapsed_s = parse_perf_task_clock(case_dir)

    helper_cpu_pct = None
    perf_elapsed_s = perf_elapsed_s or args.duration + args.perf_extra_seconds
    if task_clock_ms is not None and perf_elapsed_s:
        helper_cpu_pct = (task_clock_ms / 1000.0) / perf_elapsed_s * 100.0
    helper_cpu_source = "perf" if helper_cpu_pct is not None else None
    if helper_cpu_pct is None:
        helper_cpu_pct = helper_sampler.average
        helper_cpu_source = "procfs" if helper_cpu_pct is not None else None
    completion_rps = metric_value(k6_summary, "http_reqs", "rate")
    http_reqs_count = metric_value(k6_summary, "http_reqs", "count")
    dropped_iterations = metric_value(k6_summary, "dropped_iterations", "count") or 0.0
    scheduled_iterations = http_reqs_count + dropped_iterations if http_reqs_count is not None else None
    dropped_iterations_rate = (
        dropped_iterations / scheduled_iterations if scheduled_iterations and scheduled_iterations > 0 else None
    )
    container_cpu_avg_pct = sampler.average
    duration = case_duration(case_dir.name, args)
    load_window_rps = http_reqs_count / duration if http_reqs_count is not None else None
    load_model, load, target_rps, vus = load_spec(case_dir.name, args)

    result = {
        "case": case_dir.name,
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
        "helper_cpu_pct": helper_cpu_pct,
        "helper_cpu_source": helper_cpu_source,
        "helper_cpu_ms_per_req": cpu_ms_per_request(helper_cpu_pct, completion_rps),
        "container_cpu_avg_pct": container_cpu_avg_pct,
        "container_cpu_ms_per_req": cpu_ms_per_request(container_cpu_avg_pct, completion_rps),
    }
    write_text(case_dir / "summary.json", json.dumps(result, indent=2, sort_keys=True))
    return result


def load_spec(mode: str, args: argparse.Namespace) -> tuple[str, str, int | None, int | None]:
    if mode == "saturated":
        return "closed-loop-vus", f"{args.saturated_vus}vus", None, args.saturated_vus

    if mode == "flamegraph":
        return "constant-arrival-rate", f"{args.flamegraph_rps}rps", args.flamegraph_rps, None

    return "constant-arrival-rate", f"{args.fixed_rps}rps", args.fixed_rps, None


def case_duration(mode: str, args: argparse.Namespace) -> int:
    return args.flamegraph_duration if mode == "flamegraph" else args.duration


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


def parse_helper_pid(docker_top: str) -> str:
    for line in docker_top.splitlines():
        if re.search(r"datadog-ipc-helper|dd-ipc-helper|ddappsec-helper|helper-rust", line):
            return line.split()[0]
    raise RuntimeError("helper process not found in docker top output")


def write_proc_maps(helper_pid: str, case_dir: Path) -> None:
    try:
        write_text(case_dir / "helper.maps", Path(f"/proc/{helper_pid}/maps").read_text())
    except OSError as error:
        write_text(case_dir / "helper.maps.error", f"{error}\n")


def container_cgroup_cpu_stat(container_pid: str) -> Path | None:
    cgroup_file = Path("/proc") / container_pid / "cgroup"
    try:
        lines = cgroup_file.read_text().splitlines()
    except OSError:
        return None

    for line in lines:
        fields = line.split(":", 2)
        if len(fields) == 3:
            candidate = Path("/sys/fs/cgroup") / fields[2].lstrip("/")
            cpu_stat = candidate / "cpu.stat"
            if cpu_stat.exists():
                return cpu_stat
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
