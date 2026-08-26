import json
import sys


def extract_scenarios(data: object) -> list[str]:
    if not isinstance(data, dict):
        raise ValueError("workflow parameters must be an object")

    endtoend_defs = data.get("endtoend_defs")
    if not isinstance(endtoend_defs, dict):
        raise ValueError("endtoend_defs must be an object")

    parallel_jobs = endtoend_defs.get("parallel_jobs")
    if not isinstance(parallel_jobs, list):
        raise ValueError("endtoend_defs.parallel_jobs must be a list")

    scenarios = set()
    for job in parallel_jobs:
        if not isinstance(job, dict):
            raise ValueError("each parallel job must be an object")

        job_scenarios = job.get("scenarios")
        if not isinstance(job_scenarios, list):
            raise ValueError("each parallel job scenarios must be a list")

        for scenario in job_scenarios:
            if not isinstance(scenario, str):
                raise ValueError("each scenario must be a string")
            if not scenario or any(character.isspace() for character in scenario):
                raise ValueError(
                    "scenario names must be nonempty and contain no whitespace"
                )
            scenarios.add(scenario)

    if not scenarios:
        raise ValueError("scenario selection must not be empty")

    return sorted(scenarios)


def main() -> int:
    try:
        scenarios = extract_scenarios(json.load(sys.stdin))
    except json.JSONDecodeError as error:
        print(f"invalid JSON: {error.msg}", file=sys.stderr)
        return 1
    except ValueError as error:
        print(error, file=sys.stderr)
        return 1

    print(" ".join(scenarios))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
