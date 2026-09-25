"""Verify a real HTTP request reaches the instrumented, locked PHP runtime."""

import json
import os
from urllib.request import ProxyHandler, build_opener


def main():
    ports = json.loads(os.environ["ASSIGNED_PORTS"])
    matches = [value for label, value in ports.items() if label.endswith("//bazel/tests:http_service")]
    if len(matches) != 1:
        raise AssertionError(f"expected exactly one PHP service port: {ports}")
    opener = build_opener(ProxyHandler({}))
    with opener.open(f"http://127.0.0.1:{matches[0]}/extension", timeout=5) as response:
        body = response.read().decode()
    if not body.startswith("ddtrace:") or len(body) <= len("ddtrace:"):
        raise AssertionError(f"extension did not load in HTTP SAPI: {body!r}")


if __name__ == "__main__":
    main()
