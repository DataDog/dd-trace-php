#!/usr/bin/env -S uv run
# /// script
# requires-python = ">=3.11"
# dependencies = ["requests"]
# ///
"""
Monitor GitLab CI pipeline jobs until they complete, or download artifacts.

Exit codes:
  0 - all matching jobs passed / artifact downloaded successfully
  1 - one or more jobs failed / artifact not found
  2 - timed out with jobs still running
"""

import argparse
import json
import os
import subprocess
import sys
import tempfile
import time
import zipfile
from datetime import datetime, timezone
from pathlib import Path

import requests


session = requests.Session()

PROJECT_ID = "355"
GITLAB_URL = "https://gitlab.ddbuild.io"
POLL_INTERVAL = 30  # seconds
DEFAULT_TIMEOUT = 3600  # 60 minutes
PIPELINE_WAIT_INTERVAL = 15  # seconds between retries when no pipeline found yet
PIPELINE_WAIT_TIMEOUT = 300  # 5 minutes to wait for a pipeline to appear

# Maps download key -> exact job name in the package pipeline
ARTIFACT_JOBS: dict[str, str] = {
    "datadog-setup":        "datadog-setup.php",
    "ssi-amd64":            "package loader: [amd64]",
    "ssi-aarch64":          "package loader: [arm64]",
    "extension-amd64-gnu":  "package extension: [amd64, x86_64-unknown-linux-gnu]",
    "extension-aarch64-gnu": "package extension: [arm64, aarch64-unknown-linux-gnu]",
    "extension-amd64-musl": "package extension: [amd64, x86_64-alpine-linux-musl]",
    "extension-aarch64-musl": "package extension: [arm64, aarch64-alpine-linux-musl]",
    "extension-windows":    "package extension windows",
    "extension-asan":       "package extension asan",
}


def get_token() -> str:
    config_path = Path.home() / ".claude.json"
    config = json.loads(config_path.read_text())
    token = (
        config.get("mcpServers", {})
        .get("gitlab", {})
        .get("env", {})
        .get("GITLAB_PERSONAL_ACCESS_TOKEN")
    )
    if not token or token == "null":
        print("ERROR: Could not extract GitLab token from ~/.claude.json", file=sys.stderr)
        sys.exit(1)
    return token


def api_get(token: str, path: str, params: dict | None = None) -> list | dict:
    url = f"{GITLAB_URL}/api/v4/{path}"
    resp = session.get(url, headers={"PRIVATE-TOKEN": token}, params=params or {}, timeout=30)
    resp.raise_for_status()
    return resp.json()


def get_head_sha() -> str:
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        capture_output=True, text=True, check=True,
    )
    return result.stdout.strip()


def _search_pipeline_for_commit(token: str, sha: str, filter_str: str) -> str | None:
    """Walk parent pipelines and their child bridges to find one with matching jobs.

    Returns the pipeline ID string, or None if not found yet.
    """
    pipelines = api_get(
        token,
        f"projects/{PROJECT_ID}/pipelines",
        {"sha": sha, "per_page": 20},
    )
    if not pipelines:
        return None

    candidates: list[tuple[str, int]] = []

    for pipeline in pipelines:
        pid = pipeline["id"]

        def has_jobs(pipeline_id: int | str) -> bool:
            return any(filter_str in j["name"] for j in _iter_pipeline_jobs(token, pipeline_id))

        if has_jobs(pid):
            candidates.append((pipeline.get("updated_at", ""), pid))

        bridges = api_get(
            token,
            f"projects/{PROJECT_ID}/pipelines/{pid}/bridges",
            {"per_page": 100},
        )
        for bridge in bridges:
            downstream = bridge.get("downstream_pipeline")
            if not downstream:
                continue
            child_id = downstream["id"]
            if has_jobs(child_id):
                candidates.append((downstream.get("updated_at", ""), child_id))

    if not candidates:
        return None

    candidates.sort(key=lambda x: x[0], reverse=True)
    return str(candidates[0][1])


def find_pipeline_for_commit(token: str, sha: str, filter_str: str) -> str:
    """Find a pipeline with matching jobs, waiting up to PIPELINE_WAIT_TIMEOUT seconds."""
    deadline = time.monotonic() + PIPELINE_WAIT_TIMEOUT
    attempt = 0
    while True:
        result = _search_pipeline_for_commit(token, sha, filter_str)
        if result is not None:
            print(f"Found pipeline {result} for commit {sha[:12]}")
            return result

        remaining = deadline - time.monotonic()
        if remaining <= 0:
            print(
                f"ERROR: No pipeline with jobs matching '{filter_str}' found for commit {sha}",
                file=sys.stderr,
            )
            sys.exit(1)

        attempt += 1
        wait = min(PIPELINE_WAIT_INTERVAL, remaining)
        print(f"No pipeline found yet for {sha[:12]}, retrying in {wait:.0f}s "
              f"(attempt {attempt}, {remaining:.0f}s remaining)...")
        time.sleep(wait)


def _iter_pipeline_jobs(token: str, pipeline_id: int | str):
    """Yield every job in a pipeline, fetching pages on demand."""
    for page in range(1, 20):
        page_jobs = api_get(
            token,
            f"projects/{PROJECT_ID}/pipelines/{pipeline_id}/jobs",
            {"per_page": 100, "page": page},
        )
        yield from page_jobs
        if len(page_jobs) < 100:
            break


def download_artifact(token: str, pipeline_id: str, artifact_key: str, output_dir: Path) -> None:
    job_name = ARTIFACT_JOBS[artifact_key]
    job = next((j for j in _iter_pipeline_jobs(token, pipeline_id) if j["name"] == job_name), None)
    if not job:
        print(f"ERROR: Job '{job_name}' not found in pipeline {pipeline_id}", file=sys.stderr)
        sys.exit(1)

    job_id = job["id"]
    url = f"{GITLAB_URL}/api/v4/projects/{PROJECT_ID}/jobs/{job_id}/artifacts"
    print(f"Downloading '{job_name}' (job {job_id})...")

    with session.get(url, headers={"PRIVATE-TOKEN": token}, stream=True, timeout=60) as resp:
        resp.raise_for_status()
        total = int(resp.headers.get("content-length", 0))
        downloaded = 0
        with tempfile.NamedTemporaryFile(suffix=".zip", delete=False) as tmp:
            tmp_path = tmp.name
            for chunk in resp.iter_content(chunk_size=1024 * 1024):
                tmp.write(chunk)
                downloaded += len(chunk)
                if total:
                    print(f"\r  {downloaded / 1e6:.0f}/{total / 1e6:.0f} MB "
                          f"({downloaded / total * 100:.0f}%)", end="", flush=True)
                else:
                    print(f"\r  {downloaded / 1e6:.0f} MB", end="", flush=True)
        print()

    try:
        output_dir.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(tmp_path) as zf:
            names = zf.namelist()
            # GitLab archives artifacts with their project-relative path, so entries
            # look like "packages/foo.tar.gz". Strip the common leading directory so
            # files land directly in output_dir instead of output_dir/packages/.
            prefix = ""
            parts = [n.split("/") for n in names if not n.endswith("/")]
            if parts and all(p[0] == parts[0][0] for p in parts):
                prefix = parts[0][0] + "/"
            for member in zf.infolist():
                if member.filename.endswith("/"):
                    continue
                member.filename = member.filename.removeprefix(prefix)
                zf.extract(member, output_dir)
        print(f"Extracted {len(names)} file(s) to {output_dir}:")
        for name in names:
            if name.endswith("/"):
                continue
            p = output_dir / name.removeprefix(prefix)
            size = p.stat().st_size if p.is_file() else 0
            print(f"  {p}" + (f"  ({size / 1e6:.1f} MB)" if size else ""))
    finally:
        os.unlink(tmp_path)


def classify_jobs(jobs: list) -> tuple[list, list, list]:
    running = [j for j in jobs if j["status"] in ("running", "pending", "created")]
    passed = [j for j in jobs if j["status"] == "success"]
    failed = [j for j in jobs if j["status"] == "failed"]
    return running, passed, failed


def _make_jobs_getter(token: str, filter_str: str | None, get_roots):
    """Return a callable that collects jobs across all matching pipelines.

    get_roots: called on every poll, returns pipeline IDs to use as discovery roots.
    Each root is checked for matching jobs once (result cached in known_ids/seen_ids).
    Bridges of every root are re-checked on each poll so downstream pipelines triggered
    mid-run are discovered. filter_str=None means no filtering (all jobs included).
    """
    known_ids: set[str] = set()  # pipeline IDs confirmed to have at least one matching job
    seen_ids: set[str] = set()   # all pipeline IDs already probed (match or not)

    def getter() -> list:
        for pid in get_roots():
            pid = str(pid)
            if pid not in seen_ids:
                seen_ids.add(pid)
                if filter_str is None or any(
                    filter_str in j["name"] for j in _iter_pipeline_jobs(token, pid)
                ):
                    known_ids.add(pid)
            # Always re-check bridges: new downstream pipelines appear as bridges complete.
            bridges = api_get(
                token,
                f"projects/{PROJECT_ID}/pipelines/{pid}/bridges",
                {"per_page": 100},
            )
            for bridge in bridges:
                downstream = bridge.get("downstream_pipeline")
                if not downstream:
                    continue
                child_id = str(downstream["id"])
                if child_id not in seen_ids:
                    seen_ids.add(child_id)
                    if filter_str is None or any(
                        filter_str in j["name"] for j in _iter_pipeline_jobs(token, child_id)
                    ):
                        known_ids.add(child_id)

        all_jobs = []
        for pid in known_ids:
            all_jobs.extend(
                j for j in _iter_pipeline_jobs(token, pid)
                if filter_str is None or filter_str in j["name"]
            )
        return all_jobs

    return getter


def _wait_for_first_match(getter, sha: str, filter_str: str | None) -> None:
    """Block until getter() returns at least one job, or PIPELINE_WAIT_TIMEOUT elapses."""
    deadline = time.monotonic() + PIPELINE_WAIT_TIMEOUT
    attempt = 0
    while True:
        if getter():
            return
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            desc = f"matching '{filter_str}'" if filter_str else "any"
            print(f"ERROR: No {desc} jobs found for commit {sha}", file=sys.stderr)
            sys.exit(1)
        attempt += 1
        wait = min(PIPELINE_WAIT_INTERVAL, remaining)
        desc = f"matching '{filter_str}' " if filter_str else ""
        print(f"No {desc}jobs yet for {sha[:12]}, retrying in {wait:.0f}s "
              f"(attempt {attempt}, {remaining:.0f}s remaining)...")
        time.sleep(wait)


def _monitor_loop(jobs_getter, label: str, timeout_sec: float) -> None:
    deadline = time.monotonic() + timeout_sec
    while True:
        jobs = jobs_getter()
        running, passed, failed = classify_jobs(jobs)
        now = datetime.now(timezone.utc).strftime("%H:%M:%S")
        print(
            f"[{now}] {label}  "
            f"total={len(jobs)}  running={len(running)}  "
            f"passed={len(passed)}  failed={len(failed)}"
        )

        if failed:
            print("\nFailed jobs:")
            for j in failed:
                print(f"  [{j['id']}] {j['name']}")
            sys.exit(1)

        if not running:
            print(f"\nAll {len(passed)} jobs passed.")
            sys.exit(0)

        remaining = deadline - time.monotonic()
        if remaining <= 0:
            print(
                f"\nTIMEOUT after {timeout_sec / 60:.1f} minutes — jobs still running:",
                file=sys.stderr,
            )
            for j in running:
                print(f"  [{j['id']}] {j['name']} ({j['status']})", file=sys.stderr)
            sys.exit(2)

        time.sleep(min(POLL_INTERVAL, remaining))


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Monitor GitLab CI pipeline jobs until completion, or download artifacts."
    )
    source = parser.add_mutually_exclusive_group()
    source.add_argument(
        "--pipeline",
        metavar="ID",
        help="Numeric pipeline ID to use directly (not the IID)",
    )
    source.add_argument(
        "--commit",
        metavar="SHA",
        help="Git commit SHA — find the pipeline for this commit (default: git HEAD)",
    )
    parser.add_argument(
        "--filter",
        default="appsec",
        metavar="STR",
        help='Substring to filter job names (default: appsec). '
             'Pass "" or "none" to monitor all jobs across the full pipeline tree.',
    )
    parser.add_argument(
        "--timeout",
        type=float,
        metavar="MINUTES",
        default=DEFAULT_TIMEOUT / 60,
        help="Timeout in minutes (default: 60)",
    )
    parser.add_argument(
        "--download",
        metavar="ARTIFACT",
        choices=sorted(ARTIFACT_JOBS),
        help="Download artifact instead of monitoring. "
             f"Choices: {', '.join(sorted(ARTIFACT_JOBS))}",
    )
    parser.add_argument(
        "--output-dir",
        metavar="DIR",
        default=".",
        help="Directory for downloaded artifacts (default: current directory)",
    )
    parser.add_argument(
        "-v", "--verbose",
        action="store_true",
        help="Log every API request with timing",
    )
    args = parser.parse_args()

    if args.verbose:
        def _log(resp: requests.Response, *a, **kw) -> None:
            path = resp.url.removeprefix(f"{GITLAB_URL}/api/v4/")
            print(f"  {resp.elapsed.total_seconds():.2f}s  {path}", file=sys.stderr)
        session.hooks["response"].append(_log)

    token = get_token()

    if args.download:
        job_name = ARTIFACT_JOBS[args.download]
        if args.pipeline:
            pipeline_id = args.pipeline
            print(f"Using pipeline {pipeline_id}")
        else:
            sha = args.commit or get_head_sha()
            if not args.commit:
                print(f"Using HEAD commit: {sha[:12]}")
            pipeline_id = find_pipeline_for_commit(token, sha, job_name)
        download_artifact(token, pipeline_id, args.download, Path(args.output_dir))
        return

    # Monitoring mode
    timeout_sec = args.timeout * 60
    filter_str = args.filter if args.filter and args.filter.lower() != "none" else None

    if args.pipeline:
        pipeline_id = args.pipeline
        print(f"Using pipeline {pipeline_id}")
        getter = _make_jobs_getter(token, filter_str, lambda: [pipeline_id])
        _monitor_loop(getter, f"pipeline={pipeline_id}", timeout_sec)
    else:
        sha = args.commit or get_head_sha()
        if not args.commit:
            print(f"Using HEAD commit: {sha[:12]}")
        def get_roots():
            return [
                str(p["id"])
                for p in api_get(token, f"projects/{PROJECT_ID}/pipelines", {"sha": sha, "per_page": 20})
            ]
        getter = _make_jobs_getter(token, filter_str, get_roots)
        label = f"filter={filter_str!r}" if filter_str else f"sha={sha[:12]}"
        _wait_for_first_match(getter, sha, filter_str)
        _monitor_loop(getter, label, timeout_sec)


if __name__ == "__main__":
    main()
