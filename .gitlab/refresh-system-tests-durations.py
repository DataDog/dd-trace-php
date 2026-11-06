#!/usr/bin/env python3
"""Refresh .gitlab/system-tests-durations.json from recent master tracer-release system-tests shards.

Usage: GITLAB_TOKEN=<read_api token> .gitlab/refresh-system-tests-durations.py [PIPELINES=3]
Each scenario gets its median "=== Running X ===" duration over every weblog shard of the last
PIPELINES master pipelines that ran them. generate-package.php packs the shards from this file.
"""
import json, os, re, statistics, sys, urllib.request
from datetime import datetime

API = "https://gitlab.ddbuild.io/api/v4/projects/355"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "system-tests-durations.json")
# Never in the weblog shards: "System Tests: [parametric]" and the CWS THREAD_CONTEXT_SHARING jobs run them.
EXCLUDED = {"PARAMETRIC", "THREAD_CONTEXT_SHARING"}
LINE = re.compile(r"^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d+)Z \S+ ?(.*)$")
SHARD = re.compile(r"System Tests: \[.*, tracer-release\] \d+/\d+$")


def get(path, raw=False):
    req = urllib.request.Request(f"{API}/{path}", headers={"PRIVATE-TOKEN": os.environ["GITLAB_TOKEN"]})
    with urllib.request.urlopen(req, timeout=300) as r:
        body = r.read()
    return body.decode("utf-8", "replace") if raw else json.loads(body)


def jobs(pipeline):
    out, page = [], 1
    while True:
        batch = get(f"pipelines/{pipeline}/jobs?scope[]=success&scope[]=failed&per_page=100&page={page}")
        out += batch
        if len(batch) < 100:
            return out
        page += 1


def scenario_durations(log):
    out, cur = {}, None
    for line in log.split("\n"):
        m = LINE.match(line)
        if not m:
            continue
        ts = datetime.fromisoformat(m.group(1)).timestamp()
        body = re.sub(r"\x1b\[[0-9;]*[A-Za-z]", "", m.group(2))
        run = re.match(r"=== Running (\S+) ===", body)
        if cur and (run or ":step_script" in body and "section_end" in body):
            out[cur[0]] = ts - cur[1]
            cur = None
        if run:
            cur = (run.group(1), ts)
    return out


def main():
    want = int(sys.argv[1]) if len(sys.argv) > 1 else 3
    samples, used = {}, []
    for pipe in get("pipelines?ref=master&scope=finished&per_page=50"):
        if len(used) == want:
            break
        children = [b["downstream_pipeline"]["id"] for b in get(f"pipelines/{pipe['id']}/bridges?per_page=100")
                    if b["name"] == "package-trigger" and b.get("downstream_pipeline")]
        shards = [j for c in children for j in jobs(c) if SHARD.match(j["name"])]
        if not shards:
            continue
        used.append(pipe["id"])
        for job in shards:
            for name, secs in scenario_durations(get(f"jobs/{job['id']}/trace", raw=True)).items():
                samples.setdefault(name, []).append(secs)
        print(f"pipeline {pipe['id']}: {len(shards)} shard jobs", file=sys.stderr)
    durations = {n: round(statistics.median(v)) for n, v in sorted(samples.items()) if n not in EXCLUDED}
    doc = {"source": f"median scenario seconds over master pipelines {', '.join(map(str, used))}; "
                     "refresh with .gitlab/refresh-system-tests-durations.py",
           "scenarios": durations}
    with open(OUT, "w") as f:
        json.dump(doc, f, indent=2)
        f.write("\n")
    print(f"wrote {len(durations)} scenarios to {OUT}", file=sys.stderr)


if __name__ == "__main__":
    main()
