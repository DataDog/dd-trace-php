#!/usr/bin/env python3
"""Read the metric fields of Bazel 9.2 compact execution and remote gRPC logs.

Only the protobuf wire format is decoded; unknown fields are skipped. Schemas:
https://github.com/bazelbuild/bazel/blob/9.2.0/src/main/protobuf/spawn.proto
https://github.com/bazelbuild/bazel/blob/9.2.0/src/main/protobuf/remote_execution_log.proto
Requires the zstd CLI for compact logs. Bazel client ByteStream payload totals
include retries and compression, but exclude RPC framing, metadata, inline
ActionResult data and worker-to-CAS traffic.
"""

import argparse
from collections import Counter, defaultdict
import io
import json
from pathlib import Path
import subprocess


def varint(stream, allow_eof=False):
    value = 0
    for shift in range(0, 70, 7):
        byte = stream.read(1)
        if not byte:
            if allow_eof and shift == 0:
                return None
            raise ValueError("Truncated protobuf varint")
        value |= (byte[0] & 127) << shift
        if byte[0] < 128:
            return value
    raise ValueError("Oversized protobuf varint")


def read_exact(stream, size):
    value = stream.read(size)
    if len(value) != size:
        raise ValueError("Truncated protobuf message")
    return value


def fields(data):
    stream = io.BytesIO(data)
    result = defaultdict(list)
    while (tag := varint(stream, allow_eof=True)) is not None:
        number, wire = tag >> 3, tag & 7
        if wire == 0:
            value = varint(stream)
        elif wire == 2:
            value = read_exact(stream, varint(stream))
        elif wire in [1, 5]:
            value = read_exact(stream, 8 if wire == 1 else 4)
        else:
            raise ValueError("Unsupported protobuf wire type: " + str(wire))
        result[number].append(value)
    return result


def one(message, field, default=0):
    return message.get(field, [default])[0]


def records(stream):
    while (size := varint(stream, allow_eof=True)) is not None:
        yield fields(read_exact(stream, size))


def duration(data):
    message = fields(data)
    return one(message, 1) + one(message, 2) / 1_000_000_000


def execution_metrics(path):
    runners, mnemonics, executed, hits = Counter(), Counter(), Counter(), Counter()
    times = defaultdict(float)
    metric_names = {1: "total", 2: "parse", 3: "network", 4: "fetch", 5: "queue", 6: "setup",
                    7: "upload", 8: "execution", 9: "process_outputs", 10: "retry"}
    logical_input_bytes = 0
    process = subprocess.Popen(["zstd", "-dc", str(path)], stdout=subprocess.PIPE)
    try:
        for entry in records(process.stdout):
            if 7 not in entry:
                continue
            spawn = fields(one(entry, 7))
            mnemonic = one(spawn, 8, b"").decode()
            runner = one(spawn, 11, b"").decode()
            runners[runner] += 1
            mnemonics[mnemonic] += 1
            (hits if one(spawn, 12) else executed)[mnemonic] += 1
            metrics = fields(one(spawn, 18, b""))
            for field, name in metric_names.items():
                times[name] += duration(one(metrics, field, b""))
            logical_input_bytes += one(metrics, 11)
    finally:
        process.stdout.close()
        status = process.wait()
    if status:
        raise ValueError("zstd could not decode " + str(path))
    return dict(runners=dict(runners), mnemonics=dict(mnemonics), executed=dict(executed),
                cache_hits=dict(hits), aggregate_seconds=dict(times), logical_input_bytes=logical_input_bytes)


def transfer_metrics(path):
    methods = Counter()
    uploaded = downloaded = 0
    with path.open("rb") as stream:
        for entry in records(stream):
            methods[one(entry, 3, b"").decode()] += 1
            details = fields(one(entry, 4, b""))
            downloaded += one(fields(one(details, 5, b"")), 3)
            uploaded += one(fields(one(details, 6, b"")), 3)
    return dict(bytestream_uploaded_bytes=uploaded, bytestream_downloaded_bytes=downloaded,
                rpc_calls=dict(methods), scope="Bazel client ByteStream payloads, including retries; excluding RPC framing, inline results and worker-to-CAS traffic")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--execution", type=Path)
    parser.add_argument("--grpc", type=Path)
    args = parser.parse_args()
    result = {}
    if args.execution:
        result["execution"] = execution_metrics(args.execution)
    if args.grpc:
        result["transfers"] = transfer_metrics(args.grpc)
    print(json.dumps(result, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
