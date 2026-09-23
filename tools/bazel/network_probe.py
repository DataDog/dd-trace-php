#!/usr/bin/env python3
"""Diagnose CI access to a locked PHP SDK layer without logging credentials."""

import argparse
import json
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


def request(url, headers=None):
    try:
        with urllib.request.urlopen(
            urllib.request.Request(url, headers=headers or {}), timeout=30
        ) as response:
            response.read(1)
            return response.status, urllib.parse.urlsplit(response.url).hostname, response
    except urllib.error.HTTPError as error:
        return error.code, urllib.parse.urlsplit(error.url).hostname, error


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--arch", required=True, choices=("amd64", "arm64"))
    args = parser.parse_args()
    lock = json.loads(Path("bazel/dependencies/php_oci/images.json").read_text())
    record = next(
        record for record in lock["imports"]
        if record["platform"]["arch"] == args.arch
    )
    blob = "https://%s/v2/%s/blobs/%s" % (
        record["registry"], record["repository"], record["layers"][0]["digest"]
    )
    hosts = ("registry-1.docker.io", "auth.docker.io", "production.cloudfront.docker.com", "docker-images-prod.s3.dualstack.us-east-1.amazonaws.com", "launchpad.net", "launchpadlibrarian.net")
    proxies = urllib.request.getproxies()
    print(json.dumps({
        "probe": "proxy_configuration",
        "proxy_hosts": {key: urllib.parse.urlsplit(value).hostname for key, value in proxies.items()},
        "bypass": {host: urllib.request.proxy_bypass(host) for host in hosts},
    }), flush=True)
    status, host, _ = request(blob, {"Range": "bytes=0-0"})
    print(json.dumps({"probe": "anonymous_blob", "status": status, "host": host}), flush=True)
    token_url = record["anonymous_auth"]["token_url"]
    try:
        with urllib.request.urlopen(token_url, timeout=30) as response:
            status = response.status
            token = json.load(response).get("token")
    except urllib.error.HTTPError as error:
        status, token = error.code, None
    print(json.dumps({"probe": "anonymous_token", "status": status, "host": urllib.parse.urlsplit(token_url).hostname}), flush=True)
    if status != 200:
        return 1
    if not token:
        print(json.dumps({"probe": "anonymous_token", "error": "missing token"}), flush=True)
        return 1
    status, host, response = request(blob, {
        "Authorization": "Bearer " + token,
        "Range": "bytes=0-0",
    })
    print(json.dumps({
        "probe": "authenticated_blob",
        "status": status,
        "host": host,
        "content_range": response.headers.get("Content-Range"),
    }), flush=True)
    package = "https://launchpad.net/ubuntu/+archive/primary/+files/zlib1g_1.3.dfsg-3.1ubuntu2.2_amd64.deb"
    package_status, package_host, _ = request(package, {"Range": "bytes=0-0"})
    print(json.dumps({"probe": "rust_toolchain_package", "status": package_status, "host": package_host}), flush=True)
    return 0 if status in (200, 206) else 1


if __name__ == "__main__":
    raise SystemExit(main())
