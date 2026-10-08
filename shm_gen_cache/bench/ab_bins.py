#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = []
# ///
"""A/B-compare two prebuilt sgc_bench binaries (e.g. C++ vs Rust port).

Reuses run_interleaved() and summarize() from shm_gen_cache's
bench/ab_compare.py unchanged, so the pairing (ref,cur / cur,ref
alternation), the noise model and the verdict rule are identical; only the
"build both sides from git" step is replaced by two given binaries.

    uv run ab_bins.py --ref-bin cpp/sgc_bench --cur-bin rust/sgc_bench
    uv run ab_bins.py --ref-bin A --cur-bin A --quick       # A/A noise floor
    uv run ab_bins.py ... --bench-args=--no-huge-pages       # Linux (note the =)

"speedup" is cur/ref throughput: < 1.0 means cur (Rust) is slower.
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import shutil
import sys
import tempfile
from pathlib import Path

# ab_compare.py is an unmodified copy of shm_gen_cache's (7429fa7).
AB = Path(os.environ.get(
    "SGC_AB_COMPARE", Path(__file__).resolve().parent / "ab_compare.py"))


def load_ab():
    spec = importlib.util.spec_from_file_location("ab_compare", AB)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--ref-bin", required=True)
    p.add_argument("--cur-bin", required=True)
    p.add_argument("--rounds", type=int)
    p.add_argument("--quick", action="store_true")
    p.add_argument("--filter")
    p.add_argument("--threads")
    p.add_argument("--bench-args", default="")
    p.add_argument("--threshold", type=float, default=2.0)
    p.add_argument("--json")
    p.add_argument("--keep", action="store_true")
    args = p.parse_args()
    if args.rounds is None:
        args.rounds = 3 if args.quick else 5
    args.ref = f"{args.ref_bin} (ref)"
    ab = load_ab()
    work = Path(tempfile.mkdtemp(prefix="sgc-abbins-"))
    try:
        bins = {"ref": Path(args.ref_bin).resolve(),
                "cur": Path(args.cur_bin).resolve()}
        print(f"ref  {bins['ref']}\ncur  {bins['cur']}")
        runs = ab.run_interleaved(bins, args, work)
        summary = ab.summarize(runs, args)
        if args.json:
            Path(args.json).write_text(json.dumps(summary, indent=1))
    finally:
        if args.keep:
            print(f"kept {work}")
        else:
            shutil.rmtree(work, ignore_errors=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
