# AppSec benchmark example

This is the smallest FrankenPHP worker-mode app for collecting local AppSec
benchmark numbers. It installs the tracer package the same way as
`appsec/examples/installation`, then runs two k6 cases:

- `fixed1000`: constant-arrival-rate at 1000 rps
- `saturated`: 128 VUs for maximum throughput

Download packages before building:

```sh
uv run ../../helper-rust/scripts/check-ci-jobs.py --pipeline <pipeline-id> --download datadog-setup --output-dir packages/
uv run ../../helper-rust/scripts/check-ci-jobs.py --pipeline <pipeline-id> --download extension-aarch64-gnu --output-dir packages/
uv run ../../helper-rust/scripts/check-ci-jobs.py --pipeline <pipeline-id> --download extension-amd64-gnu --output-dir packages/
```

Keep the `dd-library-php-*-linux-gnu-*-zts.tar.gz` package for the target
architecture. The Dockerfile picks the matching `aarch64` or `x86_64` package
at build time.

Run the benchmark:

```sh
uv run ./run-benchmark.py
```

Set additional web container environment variables for comparison runs with
`--web-env`:

```sh
uv run ./run-benchmark.py --web-env DD_APPSEC_HELPER_RUST_REDIRECTION=false
```

The compose stack enables AppSec with `DD_APPSEC_ENABLED=true`, keeps
`DD_APPSEC_CLI_START_ON_RINIT=false` so the AppSec lifecycle is driven by
FrankenPHP user request callbacks, and disables remote config with
`DD_REMOTE_CONFIG_ENABLED=false`. Results are written under `results/`.
The printed table reports load context, load-window RPS, failed and dropped
request percentages, CPU cost as core milliseconds per request, and latency as
p50/p95/p99/max. `helper_ms/req` uses Linux `perf` when it is available and
permitted, otherwise it falls back to sampling `/proc/<pid>/stat` for the helper
process. `load_window_rps` is request count divided by the configured measured
duration; `completion_rps` is still kept in each case's `summary.json` as k6's
rate over total wall time, including slow requests that finish after the active
load window. Mean latency is also kept in JSON, but it is not printed because a
small tail of slow requests can dominate it.

On Linux, use `perf` for helper and worker process counters:

```sh
uv run ./run-benchmark.py \
  --perf-command "sudo -n /usr/bin/perf" \
  --perf-events "task-clock,context-switches,cpu-migrations,cycles,instructions,syscalls:sys_enter_futex,syscalls:sys_exit_futex,syscalls:sys_enter_epoll_wait,syscalls:sys_enter_recvfrom,syscalls:sys_enter_sendto,syscalls:sys_enter_recvmsg,syscalls:sys_enter_sendmsg,syscalls:sys_enter_read,syscalls:sys_enter_write" \
  --perf-warmup-seconds 0 \
  --perf-extra-seconds 0
```

Capture a combined web/helper flamegraph at a non-saturating constant load:

```sh
uv run ./run-benchmark.py prepare-flamegraph-tools

uv run ./run-benchmark.py \
  --cases flamegraph \
  --flamegraph-rps 500 \
  --flamegraph-duration 60 \
  --flamegraph-render-command flamegraph.pl
```

The flamegraph mode uses the OpenTelemetry eBPF profiler, captures raw OTLP
profiles with a local receiver, copies the relevant web/helper binaries out of
the running container, and writes folded stacks with synthetic roots under
`web/frankenphp` and `helper/libddappsec-helper-rust`. The combined folded file is
`flamegraph/folded.combined.txt`; if a renderer is available, the matching SVG
is `flamegraph/flamegraph.combined.svg`. Native address symbolization is batched
per binary and cached in `flamegraph/symbol-cache.json`. This mode does not
filter samples by request activity.

The flamegraph RPS can also be selected by short constant-arrival probes:

```sh
uv run ./run-benchmark.py \
  --cases flamegraph \
  --flamegraph-autodiscover-rps \
  --flamegraph-autodiscover-min-rps 1000 \
  --flamegraph-autodiscover-max-rps 3000 \
  --flamegraph-autodiscover-hard-max-rps 50000 \
  --flamegraph-autodiscover-step-rps 100 \
  --flamegraph-autodiscover-duration 20
```

Autodiscovery starts with the configured min/max range. If the max probe is
still accepted, it doubles the upper bound until a probe is rejected or the hard
max is reached. It then picks the highest probed RPS that stays under
`--flamegraph-autodiscover-max-dropped-rate` and
`--flamegraph-autodiscover-max-p95-ms`, then uses that RPS for the flamegraph
capture. Probe artifacts are written under `autodiscover/`.

Flamegraph mode needs a prepared OpenTelemetry eBPF profiler toolchain:

```sh
uv run ./run-benchmark.py prepare-flamegraph-tools
```

That command uses the upstream profiler Makefile and Dockerfile to create the
dev image, build `ebpf-profiler`, and build a small OTLP profile receiver. By
default it keeps the profiler checkout under
`~/.cache/dd-trace-php/appsec-benchmark/otel-ebpf-profiler` and installs:

- `~/.local/bin/ebpf-profiler`
- `~/.local/bin/benchmark-profile-receiver`

The profiler usually needs elevated privileges, so the default flamegraph
profiler command prefers `sudo -n /usr/local/bin/ebpf-profiler` when that binary
exists, otherwise it uses `sudo -n ~/.local/bin/ebpf-profiler`. If your sudoers
entry uses a different path, pass `--flamegraph-profiler-command`. If the
receiver is installed elsewhere, pass `--flamegraph-profile-receiver-command`.
`flamegraph.pl` is optional; without it the runner still writes folded stacks.
