#!/usr/bin/env -S uv run
# /// script
# requires-python = ">=3.11"
# dependencies = []
# ///
"""
Given an address (0xXXXX) and a crash event JSON file, show which /proc/self/maps
region the address falls into.

Usage: find_map_region.py <address> <json_file>
  address:   hex address, e.g. 0x7ff90ed72001
  json_file: path to crash event JSON
"""

import json
import sys
from pathlib import Path


def parse_maps_line(line: str) -> dict | None:
    """Parse a single /proc/self/maps line into a dict."""
    # Format: start-end perms offset dev inode [pathname]
    parts = line.split(None, 5)
    if len(parts) < 5:
        return None
    addr_range, perms, offset, dev, inode = parts[:5]
    pathname = parts[5].strip() if len(parts) == 6 else ""
    start_str, end_str = addr_range.split("-")
    return {
        "start": int(start_str, 16),
        "end": int(end_str, 16),
        "perms": perms,
        "offset": int(offset, 16),
        "dev": dev,
        "inode": int(inode),
        "pathname": pathname,
        "raw": line,
    }


def find_region(address: int, maps: list[str]) -> dict | None:
    for line in maps:
        region = parse_maps_line(line)
        if region and region["start"] <= address < region["end"]:
            return region
    return None


def main() -> None:
    if len(sys.argv) < 2:
        print(__doc__, file=sys.stderr)
        sys.exit(1)

    addr_arg = sys.argv[1]
    try:
        address = int(addr_arg, 16)
    except ValueError:
        print(f"Error: invalid address '{addr_arg}' (expected hex like 0x7ff90ed72001)", file=sys.stderr)
        sys.exit(1)

    if len(sys.argv) < 3:
        print(__doc__, file=sys.stderr)
        sys.exit(1)

    json_path = Path(sys.argv[2])
    if not json_path.exists():
        print(f"Error: file not found: {json_path}", file=sys.stderr)
        sys.exit(1)

    data = json.loads(json_path.read_text())
    maps = data.get("/proc/self/maps") or data.get("proc_self_maps") or []
    if not maps:
        # Try nested under a key
        for v in data.values():
            if isinstance(v, dict):
                maps = v.get("/proc/self/maps", [])
                if maps:
                    break
    if not maps:
        print("Error: could not find /proc/self/maps in JSON", file=sys.stderr)
        sys.exit(1)

    region = find_region(address, maps)
    if region is None:
        print(f"Address {addr_arg} (0x{address:x}) is NOT in any mapped region.")
        sys.exit(2)

    offset_in_region = address - region["start"]
    offset_in_file = region["offset"] + offset_in_region

    print(f"Address : {addr_arg} (0x{address:016x})")
    print(f"Region  : {region['raw']}")
    print(f"Perms   : {region['perms']}")
    print(f"Name    : {region['pathname'] or '(anonymous)'}")
    print(f"Range   : 0x{region['start']:x} – 0x{region['end']:x}  ({(region['end'] - region['start']) // 1024} KiB)")
    print(f"Offset in region : 0x{offset_in_region:x}")
    if region["pathname"]:
        print(f"Offset in file   : 0x{offset_in_file:x}")


if __name__ == "__main__":
    main()
