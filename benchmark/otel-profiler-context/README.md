# OTel profiler-context benchmark

This Linux benchmark measures the real PHP profiler time-sampling path while it
reads OTel Process and Thread Context. It loads an optimized NTS combined
`ddtrace.so`, built through Make with the existing `trigger_time_sample`
benchmark feature.

The workload calls `Datadog\Profiling\trigger_time_sample()` from PHP. On the
PHP 8.3 benchmark runtime, returning from that internal function processes the
pending profiler VM interrupt, so every iteration walks the PHP stack, decodes
Thread Context, resolves effective profile tags, and submits a sample.

## Build and run

```sh
benchmark/otel-profiler-context/run.sh build
benchmark/otel-profiler-context/run.sh run
```

The modes are:

- `inactive`: no active OTel Thread Context.
- `matching`: active context identity matches the process/base tags.
- `overrides`: stable thread-context service, environment, and version differ
  from the process/base tags.

Run one mode or override the defaults:

```sh
benchmark/otel-profiler-context/run.sh run overrides 20 50000
EPOCHS=30 ITERATIONS=100000 benchmark/otel-profiler-context/run.sh run
```

Each epoch emits one JSON object. After flushing its final result, the workload
kills its disposable container process so extension shutdown remains outside
the benchmark.

Summarize a saved run with:

```sh
jq -s 'group_by(.mode)[] | {
  mode: .[0].mode,
  median_ns: (map(.ns_per_sample) | sort | .[length / 2 | floor]),
  min_ns: (map(.ns_per_sample) | min),
  max_ns: (map(.ns_per_sample) | max)
}' results.jsonl
```

The important comparison for Thread Context allocation changes is the extra
cost of `overrides` relative to `matching`, not just the absolute sample cost.

## Noise control and counters

The measured container is pinned to CPU 2 and 4 GiB by default. Select another
Docker VM or native Linux CPU with `CPU=...`.

```sh
CPU=4 benchmark/otel-profiler-context/run.sh run overrides
```

On a native Linux Docker host, collect hardware counters with:

```sh
benchmark/otel-profiler-context/run.sh perf overrides 50000
```

Docker Desktop commonly does not expose PMU counters. Interleave baseline and
candidate runs, keep Docker resource settings fixed, and avoid other CPU-heavy
work while comparing revisions.

Run `benchmark/otel-profiler-context/run.sh clean` to remove the dedicated build
volume.
