import copy
import json
import os
import time
from functools import wraps
from typing import Any, Callable, Iterable, Optional


PREFIX = "BLRP_DIAGNOSTIC "
_ORIGINAL_WAIT = None


def _messages(events: Iterable[dict]) -> Iterable[tuple[dict, dict]]:
    for event in events:
        if not isinstance(event, dict):
            continue
        if event.get("request_type") == "message-batch":
            for message in event.get("payload", []):
                if (
                    isinstance(message, dict)
                    and message.get("application", {}).get("language_version") != "SIDECAR"
                ):
                    yield message, event
        elif event.get("application", {}).get("language_version") != "SIDECAR":
            yield event, event


def _event_summaries(events: Iterable[dict]) -> list[dict]:
    summaries = []
    for message, envelope in _messages(events):
        payload = message.get("payload")
        configurations = payload.get("configuration", []) if isinstance(payload, dict) else []
        configuration_names = sorted(
            {
                configuration.get("name")
                for configuration in configurations
                if isinstance(configuration, dict) and isinstance(configuration.get("name"), str)
            }
        )
        summaries.append(
            {
                "request_type": message.get("request_type"),
                "runtime_id": message.get("runtime_id", envelope.get("runtime_id")),
                "tracer_time": message.get("tracer_time", envelope.get("tracer_time")),
                "configuration_names": configuration_names,
            }
        )
    return summaries


def _emit_record(emit: Callable[[str], None], record: dict) -> None:
    emit(PREFIX + json.dumps(record, sort_keys=True, separators=(",", ":")))


def _default_emit(line: str) -> None:
    print(line, flush=True)
    output_path = os.environ.get("BLRP_DIAGNOSTIC_OUTPUT")
    if output_path:
        with open(output_path, "a", encoding="utf-8") as output:
            output.write(line + "\n")


def diagnostic_wait(
    original_wait: Callable[..., dict],
    agent: Any,
    *args: Any,
    expected_name: str,
    observation_seconds: float = 5.0,
    poll_seconds: float = 0.05,
    monotonic: Callable[[], float] = time.monotonic,
    sleep: Callable[[float], None] = time.sleep,
    emit: Optional[Callable[[str], None]] = None,
    **kwargs: Any,
) -> dict:
    emit = emit or _default_emit
    original_telemetry = agent.telemetry
    original_poll_start = monotonic()
    original_poll_count = 0
    had_instance_telemetry = "telemetry" in vars(agent)

    def observed_telemetry(*telemetry_args: Any, **telemetry_kwargs: Any) -> Any:
        nonlocal original_poll_count
        events = original_telemetry(*telemetry_args, **telemetry_kwargs)
        original_poll_count += 1
        _emit_record(
            emit,
            {
                "phase": "original-wait-snapshot",
                "poll": original_poll_count,
                "elapsed_ms": round((monotonic() - original_poll_start) * 1000),
                "events": _event_summaries(events),
            },
        )
        return events

    agent.telemetry = observed_telemetry
    try:
        original_result = original_wait(agent, *args, **kwargs)
    finally:
        if had_instance_telemetry:
            agent.telemetry = original_telemetry
        else:
            del agent.telemetry
    original_result_snapshot = copy.deepcopy(original_result)
    original_return_time = monotonic()
    returned_names = sorted(name for name in original_result_snapshot if isinstance(name, str))
    _emit_record(
        emit,
        {
            "phase": "original-return",
            "elapsed_ms": 0,
            "returned_configuration_names": returned_names,
            "expected_name_present_at_original_return": expected_name in original_result_snapshot,
            "expected_configuration_name": expected_name,
        },
    )

    deadline = original_return_time + observation_seconds
    previous_fingerprint = None
    latest_summaries = []
    target_present_during_post_boundary_window = False
    while True:
        now = monotonic()
        try:
            latest_summaries = _event_summaries(agent.telemetry(clear=False))
            error_type = None
        except Exception as error:
            latest_summaries = []
            error_type = type(error).__name__

        target_present = any(
            expected_name in event["configuration_names"] for event in latest_summaries
        )
        target_present_during_post_boundary_window = (
            target_present_during_post_boundary_window or target_present
        )
        fingerprint = json.dumps(
            {"events": latest_summaries, "error_type": error_type},
            sort_keys=True,
            separators=(",", ":"),
        )
        if fingerprint != previous_fingerprint:
            _emit_record(
                emit,
                {
                    "phase": "post-boundary-snapshot",
                    "elapsed_ms": round((now - original_return_time) * 1000),
                    "events": latest_summaries,
                    "target_present": target_present,
                    "error_type": error_type,
                },
            )
            previous_fingerprint = fingerprint

        if now >= deadline:
            break
        sleep(min(poll_seconds, deadline - now))

    _emit_record(
        emit,
        {
            "phase": "observation-complete",
            "elapsed_ms": round((monotonic() - original_return_time) * 1000),
            "target_present_during_post_boundary_window": target_present_during_post_boundary_window,
            "expected_name_present_at_original_return": expected_name in original_result_snapshot,
        },
    )
    return original_result_snapshot


def pytest_configure(config: Any) -> None:
    del config
    if os.environ.get("BLRP_TELEMETRY_DIAGNOSTIC") != "1":
        return

    from utils.docker_fixtures import TestAgentAPI

    global _ORIGINAL_WAIT
    if _ORIGINAL_WAIT is not None:
        return

    _ORIGINAL_WAIT = TestAgentAPI.wait_for_telemetry_configurations
    expected_name = os.environ["BLRP_EXPECTED_CONFIGURATION_NAME"]
    observation_seconds = float(os.environ.get("BLRP_OBSERVATION_SECONDS", "5"))

    @wraps(_ORIGINAL_WAIT)
    def wrapped(agent: Any, *args: Any, **kwargs: Any) -> dict:
        return diagnostic_wait(
            _ORIGINAL_WAIT,
            agent,
            *args,
            expected_name=expected_name,
            observation_seconds=observation_seconds,
            **kwargs,
        )

    TestAgentAPI.wait_for_telemetry_configurations = wrapped


def pytest_unconfigure(config: Any) -> None:
    del config
    global _ORIGINAL_WAIT
    if _ORIGINAL_WAIT is None:
        return

    from utils.docker_fixtures import TestAgentAPI

    TestAgentAPI.wait_for_telemetry_configurations = _ORIGINAL_WAIT
    _ORIGINAL_WAIT = None
