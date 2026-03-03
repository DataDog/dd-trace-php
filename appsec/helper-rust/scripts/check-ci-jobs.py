#!/usr/bin/env -S uv run
# /// script
# requires-python = ">=3.11"
# dependencies = ["requests"]
# ///
"""
Monitor GitLab CI pipeline jobs until they complete, then exit.

Exit codes:
  0 - all matching jobs passed
  1 - one or more jobs failed
  2 - timed out with jobs still running
"""

import argparse
import json
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import requests


PROJECT_ID = "355"
GITLAB_URL = "https://gitlab.ddbuild.io"
POLL_INTERVAL = 30  # seconds
DEFAULT_TIMEOUT = 3600  # 60 minutes


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
    resp = requests.get(url, headers={"PRIVATE-TOKEN": token}, params=params or {}, timeout=30)
    resp.raise_for_status()
    return resp.json()


def get_pipeline_jobs(token: str, pipeline_id: int | str, filter_str: str) -> list:
    jobs = api_get(
        token,
        f"projects/{PROJECT_ID}/pipelines/{pipeline_id}/jobs",
        {"per_page": 100},
    )
    return [j for j in jobs if filter_str in j["name"]]


def get_head_sha() -> str:
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        capture_output=True, text=True, check=True,
    )
    return result.stdout.strip()


def find_pipeline_for_commit(token: str, sha: str, filter_str: str) -> str:
    """Walk parent pipelines and their child bridges to find one with matching jobs."""
    pipelines = api_get(
        token,
        f"projects/{PROJECT_ID}/pipelines",
        {"sha": sha, "per_page": 20},
    )
    if not pipelines:
        print(f"ERROR: No pipelines found for commit {sha}", file=sys.stderr)
        sys.exit(1)

    # (updated_at, pipeline_id) candidates that actually contain matching jobs
    candidates: list[tuple[str, int]] = []

    for pipeline in pipelines:
        pid = pipeline["id"]

        # Check the pipeline itself first (unlikely for child-pipeline projects, but safe)
        if get_pipeline_jobs(token, pid, filter_str):
            candidates.append((pipeline.get("updated_at", ""), pid))

        # Check child pipelines reachable via bridge jobs
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
            if get_pipeline_jobs(token, child_id, filter_str):
                candidates.append((downstream.get("updated_at", ""), child_id))

    if not candidates:
        print(
            f"ERROR: No pipeline with jobs matching '{filter_str}' found for commit {sha}",
            file=sys.stderr,
        )
        sys.exit(1)

    candidates.sort(key=lambda x: x[0], reverse=True)
    _, best_id = candidates[0]
    print(f"Found pipeline {best_id} for commit {sha[:12]}")
    return str(best_id)


def classify_jobs(jobs: list) -> tuple[list, list, list]:
    running = [j for j in jobs if j["status"] in ("running", "pending", "created")]
    passed = [j for j in jobs if j["status"] == "success"]
    failed = [j for j in jobs if j["status"] == "failed"]
    return running, passed, failed


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Monitor GitLab CI pipeline jobs until completion."
    )
    source = parser.add_mutually_exclusive_group()
    source.add_argument(
        "--pipeline",
        metavar="ID",
        help="Numeric pipeline ID to monitor directly (not the IID)",
    )
    source.add_argument(
        "--commit",
        metavar="SHA",
        help="Git commit SHA — find the pipeline for this commit (default: git HEAD)",
    )
    parser.add_argument(
        "--filter",
        default="appsec",
        help="Substring to filter job names (default: appsec)",
    )
    parser.add_argument(
        "--timeout",
        type=float,
        metavar="MINUTES",
        default=DEFAULT_TIMEOUT / 60,
        help="Timeout in minutes (default: 60)",
    )
    args = parser.parse_args()

    token = get_token()

    # Resolve pipeline ID
    if args.pipeline:
        pipeline_id = args.pipeline
        print(f"Using pipeline {pipeline_id}")
    else:
        sha = args.commit or get_head_sha()
        if not args.commit:
            print(f"Using HEAD commit: {sha[:12]}")
        pipeline_id = find_pipeline_for_commit(token, sha, args.filter)

    timeout_sec = args.timeout * 60

    deadline = time.monotonic() + timeout_sec

    while True:
        jobs = get_pipeline_jobs(token, pipeline_id, args.filter)

        if not jobs:
            print(
                f"ERROR: No jobs found matching filter '{args.filter}' in pipeline {pipeline_id}",
                file=sys.stderr,
            )
            sys.exit(1)

        running, passed, failed = classify_jobs(jobs)
        now = datetime.now(timezone.utc).strftime("%H:%M:%S")
        print(
            f"[{now}] pipeline={pipeline_id}  "
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


if __name__ == "__main__":
    main()
