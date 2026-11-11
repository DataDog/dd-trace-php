# data-streams-monitoring

Kafka (php-rdkafka) and RabbitMQ (php-amqplib) produce/consume workloads used
to measure how much Data Streams Monitoring (DSM) adds on top of APM. The
files have no tracer-specific code; the caller selects the scenario by loading
the ddtrace extension or not and by `DD_DATA_STREAMS_ENABLED`.

- `run_timeit.php`: timing harness, `BROKER` (`kafka`, default, or
  `rabbitmq`) picks the workload.
- `kafka_throughput.php`, `amqp_throughput.php`: one iteration per broker.
- `common.php`: what both share (message count, headers, `COMMIT_EVERY`).

Each iteration: fresh single-partition topic (queue) and consumer group;
(untimed) build the clients, create the topic, join the group; (timed)
produce 1000 unkeyed 1024-byte messages with headers `key0..key4` ->
`value0..value4`, then flush; (timed) consume them with a synchronous commit
every `COMMIT_EVERY` messages and after the last one; (untimed) teardown, then
sample process RSS. Duration per iteration = produce + consume.

## CI

`dsm-throughput` in `.gitlab/benchmarks.yml` runs it on the
[benchmarking-platform](https://github.com/DataDog/benchmarking-platform)
branch `php/data-streams-monitoring` (automatic on non-scheduled `master`
pipelines, manual elsewhere, allowed to fail). It passes this checkout (`DD_TRACE_PHP_SRC_DIR`) and the
pipeline's packaged build (`DD_TRACE_PHP_PACKAGE_DIR`), so the workload and
the tracer are the commit under test. The `steps/` and `bp-runner.yml`
mentioned in the files' comments, the scenarios and the report are in that
branch; see its README.

## Run

Requires PHP 8.3 with php-rdkafka 6+, Kafka on `localhost:9092`, and an agent
(or mock agent) that accepts traces and `/v0.1/pipeline_stats`. RabbitMQ also
needs php-amqplib (`AMQP_AUTOLOAD`, default
`/opt/php-amqplib/vendor/autoload.php`) and a broker on `localhost:5672`.

```
# baseline: PHP without the ddtrace extension
php benchmark/data-streams-monitoring/run_timeit.php
# apm-only (apm+dsm: DD_DATA_STREAMS_ENABLED=true): ddtrace installed and loaded
ENABLE_TRACER=true DD_DATA_STREAMS_ENABLED=false \
    php benchmark/data-streams-monitoring/run_timeit.php
```

The platform's `steps/start-benchmark.sh` does this per experiment (it loads
ddtrace through `PHP_INI_SCAN_DIR` only in the tracer experiments).

| Variable | Default | |
|---|---|---|
| `BROKER` | `kafka` | `kafka` or `rabbitmq` |
| `ENABLE_TRACER` | unset | `true`: ddtrace must be loaded; otherwise it must not be |
| `DD_DATA_STREAMS_ENABLED` | unset | `true`: apm+dsm, checked against the tracer's config |
| `COMMIT_EVERY` | 100 | sync commit every N messages; integer >= 1 |
| `NUM_THREADS` | 1 | only 1 is supported |
| `WARMUP` / `COUNT` | 5 / 25 | discarded / timed iterations |
| `KAFKA_BOOTSTRAP_SERVERS` | `localhost:9092` | |
| `KAFKA_TOPIC` | `benchmark-topic` | base name; nonce and iteration are appended |
| `AMQP_HOST` / `AMQP_PORT` | `localhost` / 5672 | |
| `AMQP_QUEUE` | `benchmark-queue` | base name; iteration is appended |
| `TIMEIT_OUTPUT` | `results.json` | result JSON |

## Output

`TIMEIT_OUTPUT` holds one result over the timed iterations:

```
[{"median": <duration ms>, "metrics": {
  "process.internal_duration_ms.median": <ms>,
  "process.internal_duration_ms.std_err": <ms>,
  "process.rss_bytes.median": <bytes>, "phase.produce_ms.median": <ms>,
  "phase.consume_ms.median": <ms>, "config.commit_every": <int>, ...},
  "data": [...]}]
```

The extra metrics (sidecar CPU/RSS, Zend heap, iteration wall time) and the
per-iteration `data` are documented at the top of `run_timeit.php`.
