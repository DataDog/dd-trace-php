import json
import os
import sys


def fail(message):
    raise SystemExit(f"Failed to select tracer-release scenarios: {message}")


def validate_scenario_group(group, location):
    if not isinstance(group, list) or any(
        not isinstance(scenario, str) or not scenario or any(character.isspace() for character in scenario)
        for scenario in group
    ):
        fail(f"expected {location} to be a list of non-empty names without whitespace")
    return group


def main():
    try:
        data = json.load(sys.stdin)
    except (json.JSONDecodeError, UnicodeDecodeError) as error:
        fail(f"invalid scenario JSON: {error}")

    if not isinstance(data, dict):
        fail("expected a JSON object")

    try:
        shard_count = int(os.environ["CI_NODE_TOTAL"])
        shard_number = int(os.environ["CI_NODE_INDEX"])
    except (KeyError, ValueError) as error:
        fail(f"invalid shard configuration: {error}")

    if shard_count != 4 or not 1 <= shard_number <= shard_count:
        fail(f"invalid shard {shard_number} of {shard_count}")

    endtoend_defs = data.get("endtoend_defs")
    if not isinstance(endtoend_defs, dict):
        fail("expected endtoend_defs to be an object")

    parallel_jobs = endtoend_defs.get("parallel_jobs")
    if not isinstance(parallel_jobs, list) or not parallel_jobs:
        fail("expected endtoend_defs.parallel_jobs to be a non-empty list")

    scenarios = set()
    for index, job in enumerate(parallel_jobs):
        if not isinstance(job, dict) or "scenarios" not in job:
            fail(f"expected endtoend_defs.parallel_jobs[{index}] to contain scenarios")
        scenarios.update(
            validate_scenario_group(
                job["scenarios"],
                f"endtoend_defs.parallel_jobs[{index}].scenarios",
            )
        )

    for name, value in data.items():
        if name in ("endtoend", "endtoend_defs"):
            continue
        if isinstance(value, dict) and "scenarios" in value:
            scenarios.update(validate_scenario_group(value["scenarios"], f"{name}.scenarios"))

    selected = sorted(scenarios)[shard_number - 1::shard_count]
    if not selected:
        fail(f"shard {shard_number} of {shard_count} is empty")

    print(" ".join(selected))


if __name__ == "__main__":
    main()
