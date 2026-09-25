"""Bazel 9.2 / Buildbarn accounting, with no generated protobuf dependency.

Wire field numbers come from remote_execution_log.proto (Bazel 9.2), REAPI v2,
google.longrunning.Operation and Buildbarn resourceusage.POSIXResourceUsage.
See bazel/CI.md for source links and the limits of this accounting.
"""

import importlib.util
from pathlib import Path
import subprocess

_spec = importlib.util.spec_from_file_location(
    "runtime_log_metrics", Path(__file__).with_name("runtime-log-metrics.py"))
wire = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(wire)


def execution_metrics(path):
    result = dict(spawns=0, remote_executed=0, remote_cache_hits=0,
                  local_executed=0, failed_spawns=0, execution_seconds=0.0,
                  queue_seconds=0.0, runners={})
    process = subprocess.Popen(["zstd", "-dc", str(path)], stdout=subprocess.PIPE)
    try:
        for entry in wire.records(process.stdout):
            if 7 not in entry:
                continue
            spawn = wire.fields(wire.one(entry, 7))
            runner = wire.one(spawn, 11, b"").decode()
            cached = bool(wire.one(spawn, 12))
            result["spawns"] += 1
            result["runners"][runner] = result["runners"].get(runner, 0) + 1
            if runner.startswith("remote"):
                result["remote_cache_hits" if cached else "remote_executed"] += 1
            else:
                result["local_executed"] += 1
            result["failed_spawns"] += bool(wire.one(spawn, 9) or wire.one(spawn, 10, b""))
            metrics = wire.fields(wire.one(spawn, 18, b""))
            # These are elapsed durations, NEVER CPU measurements.
            result["execution_seconds"] += wire.duration(wire.one(metrics, 8, b""))
            result["queue_seconds"] += wire.duration(wire.one(metrics, 5, b""))
    finally:
        process.stdout.close()
        status = process.wait()
    if status:
        raise ValueError("Invalid compact execution log: " + str(path))
    total = result["remote_executed"] + result["remote_cache_hits"]
    result["remote_hit_rate"] = result["remote_cache_hits"] / total if total else None
    return result


def remote_cpu_metrics(path):
    """Count new execution CPU once per operation, including completed retries.

    GetActionResult contains HISTORICAL CPU; never charge it to this build.
    Execute and WaitExecution can return the same operation on reconnection.
    Missing resource metadata and unfinished/failed RPCs make totals incomplete.
    """
    operations, completed = set(), {}
    rpc_errors = 0
    anonymous = 0
    with Path(path).open("rb") as stream:
        for entry in wire.records(stream):
            details = wire.fields(wire.one(entry, 4, b""))
            for field in (7, 9):  # ExecuteDetails, WaitExecutionDetails
                if field not in details:
                    continue
                rpc_errors += bool(wire.one(wire.fields(wire.one(entry, 2, b"")), 1))
                call = wire.fields(wire.one(details, field))
                for raw in call.get(2, []):
                    operation = wire.fields(raw)
                    name = wire.one(operation, 1, b"")
                    if not name:
                        anonymous += 1
                        continue
                    operations.add(name)
                    if not wire.one(operation, 3) or 5 not in operation:
                        continue
                    response_any = wire.fields(wire.one(operation, 5))
                    if not wire.one(response_any, 1, b"").endswith(b"/build.bazel.remote.execution.v2.ExecuteResponse"):
                        continue
                    response = wire.fields(wire.one(response_any, 2, b""))
                    # REAPI ExecuteResponse.cached_result is field 4. Field 2
                    # is google.rpc.Status and must never be treated as a hit.
                    if wire.one(response, 4):
                        completed[name] = (True, 0.0)
                        continue
                    action = wire.fields(wire.one(response, 1, b""))
                    metadata = wire.fields(wire.one(action, 9, b""))
                    usage = []
                    for raw_aux in metadata.get(11, []):
                        auxiliary = wire.fields(raw_aux)
                        if wire.one(auxiliary, 1, b"").endswith(b"/buildbarn.resourceusage.POSIXResourceUsage"):
                            resource = wire.fields(wire.one(auxiliary, 2, b""))
                            usage.append(sum(wire.duration(wire.one(resource, f, b"")) for f in (1, 2)))
                    # Multiple usage records may overlap: don't silently sum them.
                    completed[name] = (False, usage[0] if len(usage) == 1 else None)
    executed = [cpu for cached, cpu in completed.values() if not cached]
    missing = sum(cpu is None for cpu in executed)
    unfinished = len(operations - completed.keys())
    complete = not (missing or unfinished or rpc_errors or anonymous)
    return dict(
        cpu_seconds=sum(cpu for cpu in executed if cpu is not None) if complete else None,
        observed_cpu_seconds=sum(cpu for cpu in executed if cpu is not None),
        executed_operations=len(executed),
        cached_operations=sum(cached for cached, _ in completed.values()),
        missing_usage=missing, unfinished_operations=unfinished,
        rpc_errors=rpc_errors, anonymous_operations=anonymous, complete=complete,
    )


def validate_remote(execution, cpu, require_execution=False):
    if not execution["spawns"]:
        raise ValueError("No spawn evidence: a retained local build cannot prove remote caching")
    if execution["local_executed"]:
        raise ValueError("CI executed local spawns: " + str(execution["runners"]))
    if execution["failed_spawns"]:
        raise ValueError("Failed actions in execution log")
    if require_execution and not execution["remote_executed"]:
        raise ValueError("Forced RBE run did not execute any actions remotely")
    # A log that lost Execute records must not claim zero worker CPU.
    if cpu["executed_operations"] < execution["remote_executed"]:
        cpu["complete"] = False
        cpu["cpu_seconds"] = None
