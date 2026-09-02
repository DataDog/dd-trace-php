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

For `--server fpm`, keep the corresponding non-ZTS package instead:
`dd-library-php-*-linux-gnu-<api>.tar.gz`. `Dockerfile.fpm` selects the package
matching the PHP-FPM image's architecture and module API.

Run the benchmark:

```sh
uv run ./run-benchmark.py
```

On Linux, the runner sets every cpufreq policy to the `performance` governor
before starting the Compose stack and restores each policy's original governor
in its outermost cleanup block. The observed original, configured, and restored
state is recorded in `cpu-governor.json`. The default write command is
`sudo -n tee` for an unprivileged user and `tee` for root. Override the
tee-compatible command with `--cpu-governor-write-command`, or opt out with
`--cpu-governor none` on systems where the governor is managed externally.

To benchmark the PHP profiler with the FrankenPHP/PHP 8.5 ZTS image, place its
matching CI release module at `packages/datadog-profiling-zts.so`, opt into
installing the module at image build time, and enable profiling in the web
container:

```bash
INSTALL_DATADOG_PROFILING=1 uv run ./run-benchmark.py \
  --web-env DD_PROFILING_ENABLED=1
```

The build fails if profiling is requested without the matching module. The
module is loaded after `ddtrace.so`; the environment variable controls whether
the profiler is active.

Set additional web container environment variables for comparison runs with
`--web-env`:

```sh
uv run ./run-benchmark.py --web-env DD_APPSEC_HELPER_RUST_REDIRECTION=false
```

The compose stack enables AppSec with `DD_APPSEC_ENABLED=true`, keeps
`DD_APPSEC_CLI_START_ON_RINIT=false` so the AppSec lifecycle is driven by
FrankenPHP user request callbacks, and disables remote config with
`DD_REMOTE_CONFIG_ENABLED=false`. It also enables the narrowly scoped
`DD_APPSEC_TESTING_INVALID_COMMAND` API so the readiness endpoint can call
`datadog\appsec\testing\request_exec()`, whose return value reports whether the
WAF command succeeded. The public `push_addresses()` API is not sufficient for
this check because it returns `true` after attempting `dd_request_exec()`,
including when that command reports a communication error.

The runner starts the shared helper, then polls the private readiness endpoint
until AppSec is enabled and that benign `request_exec` WAF round-trip succeeds.
It repeats the functional check after each measured case and rejects the result
if the helper shut down during the case.
The helper bootstrap raises its inherited soft `RLIMIT_NOFILE` to 65536 by
default and verifies the live helper process limit before collecting results.
This avoids the low 1024-descriptor limit inherited by a plain `docker exec`
process. Override the verified limit with `--helper-nofile`.
Measured cases wait for the helper process before collecting process-level CPU
data, and include the helper log in their artifacts. Results are written under
`results/`.
The printed table reports load context, load-window RPS, failed and dropped
request percentages, CPU cost as core milliseconds per request, and latency as
p50/p95/p99/max. By default, no profiler or `perf` counters are attached.
Headline container and helper CPU costs use exact start/end deltas from cgroup
`cpu.stat` and `/proc/<pid>/stat`, divided by completed requests. The periodic
CPU samples remain available for diagnosing instability, but are only used as a
fallback when boundary counters are unavailable.

Every measured case also writes `boundary-counters.json`. It contains
process-wide scheduler deltas aggregated across all threads of the web and
helper processes: voluntary and involuntary context switches, CPU migrations,
runtime, run-queue wait time, and scheduling slices. Per-request scheduler
values are copied into `summary.json`.

`load_window_rps` is request count divided by the configured measured duration;
`completion_rps` is still kept in each case's `summary.json` as k6's rate over
total wall time, including slow requests that finish after the active load
window. Mean latency is also kept in JSON, but it is not printed because a
small tail of slow requests can dominate it.

Capture cgroup and per-process memory through repeated load/drain cycles:

```sh
uv run ./run-benchmark.py \
  --cases memory \
  --memory-rps 500 \
  --memory-cycles 5 \
  --memory-load-seconds 120 \
  --memory-idle-seconds 20 \
  --memory-initial-idle-seconds 20 \
  --memory-sample-interval 5
```

To run the same protocol under PHP-FPM 8.5 NTS, use `--server fpm`. The FPM
image uses eight static children and `pm.max_requests = 0`, so workers are not
recycled during a soak. FPM starts its helper through a real web request and
inherits the container's 65,536-descriptor limit; the CLI-only helper bootstrap
used by the FrankenPHP harness is deliberately not run. Readiness checks carry
the serving process ID and do not pass until every persistent FPM child has
completed a real WAF round-trip:

```sh
uv run ./run-benchmark.py --server fpm --cases memory --memory-rps 2000
```

Memory mode samples the web container's cgroup v2 `memory.current`,
`memory.peak`, and `memory.stat`, plus `smaps_rollup` and `/proc/<pid>/status`
for the web and helper processes. Under FPM, process values are aggregates
across nginx, the FPM master and all FPM children; multiple helper processes
are aggregated as well. It reports RSS, PSS, USS/private memory,
anonymous memory, file-backed memory, virtual size, high-water RSS, swap,
thread count, and file-descriptor count. Full `smaps` snapshots are saved after
the initial idle period and after the final drain. When the benchmark user
cannot read another user's `smaps_rollup`, mappings, and descriptor table, the
runner reads those files as root through the existing web container. That adds
one short `docker exec` per sampling interval; cgroup values are captured before
the reader starts, and a five-second default interval limits observer overhead.
If the harness itself has sufficient `/proc` access, it reads the files
directly and starts no reader process.

The default 20-second drain is intentional: it is long enough for request
queues and transient mappings to settle without unnecessarily extending the
soak. Helper processes can rotate during a sustained run, so memory mode
records every PID generation and samples the currently live helper when one is
present. Per-helper trends must be interpreted within a generation. Cgroup
anonymous memory is the authoritative cross-generation leak signal because it
does not reset when a helper process is replaced; post-idle process PSS/USS is
corroborating evidence only when those floors come from the same generation.

`memory-analysis.json` calculates a robust Theil-Sen slope across the
post-idle memory floors and normalizes it per million completed requests. It
also reports a post-warm-up slope that excludes the initial baseline and a tail
slope that excludes the first loaded floor. A monotonic post-idle increase in
helper PSS/USS and cgroup anonymous memory is a leak signal; a stable post-idle
floor with a higher load-time peak is allocator retention or normal high-water
behavior rather than evidence of a leak.

Capture the helper's exact outstanding libc allocations with
`ebpf-memory-profiler`:

```sh
uv run ./run-benchmark.py \
  --server fpm \
  --cases memory-profiler \
  --memory-profiler-bin /absolute/path/to/ebpf-memory-profiler \
  --memory-profiler-rps 40 \
  --memory-profiler-duration 300
```

The profiler executable has no inferred or default location:
`--memory-profiler-bin` is required whenever `memory-profiler` is selected. The
path is resolved on the host and bind-mounted read-only into a privileged
profiler container. The profiler container joins the web container's PID
namespace and attaches to the one `dd-ipc-helper` process; the run is rejected
if there is not exactly one helper or if its PID changes.

The default 1 GiB event ring and 16,384-entry inactive stack cache can be
changed with `--memory-profiler-ring-buffer-mib` and
`--memory-profiler-stack-cache-entries`. The runner waits for the profiler's
attachment marker before starting k6, performs the full AppSec validation after
load, waits 15 seconds for transient allocations to drain, then stops the
profiler gracefully. It copies `memory-profiler/outstanding-allocations.jsonl`
out of the profiler container and rejects the result unless the profile header
has `incomplete=false`, its target PID matches the helper namespace PID, every
allocation references a recorded stack, and the record counts and bytes match
the header. The validated aggregate is written to
`memory-profiler/profile-analysis.json`.

On Linux, explicitly opt into `perf` for diagnostic helper and worker process
counters. These counters can perturb CPU and latency, so compare them only with
identically instrumented runs:

```sh
uv run ./run-benchmark.py \
  --instrumentation perf \
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

By default, the runner bind-mounts the prepared `ebpf-profiler` binary into a
privileged `ubuntu:24.04` container. The container joins the host PID, network,
and cgroup namespaces, mounts tracefs at `/sys/kernel/tracing`, and sends OTLP
profiles to the host receiver. The runner assigns the container a project-scoped
name, stops it gracefully after capture, and force-removes it if graceful
shutdown does not complete. The backend, image, binary path and SHA-256,
namespace configuration, and profiler options are recorded in
`flamegraph/capture-config.json`.

Profile samples are not converted to CPU using the profiler's nominal sample
frequency. Instead, `profile-normalization.json` scales each role by the
independently measured boundary CPU: helper CPU comes from `/proc`, and web CPU
is container CPU minus helper CPU. `profile-cpu.helper.tsv`,
`profile-cpu.web.tsv`, and `profile-cpu.combined.tsv` contain each folded
stack's sample share and normalized CPU milliseconds per request.

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
build image, build `ebpf-profiler`, and build a small OTLP profile receiver. By
default it keeps the profiler checkout under
`~/.cache/dd-trace-php/appsec-benchmark/otel-ebpf-profiler` and installs:

- `~/.local/bin/ebpf-profiler`
- `~/.local/bin/benchmark-profile-receiver`

Override the Docker runtime image with `--flamegraph-profiler-image` or the
bind-mounted binary with `--flamegraph-profiler-bin`. To run the profiler
natively instead, select the native backend and provide a command with whatever
privilege mechanism the host requires:

```sh
uv run ./run-benchmark.py \
  --cases flamegraph \
  --flamegraph-profiler-backend native \
  --flamegraph-profiler-command "sudo -n /usr/local/bin/ebpf-profiler"
```

`--flamegraph-profiler-stop-command` remains available as an additional cleanup
hook for an externally managed native profiler. If the receiver is installed
elsewhere, pass `--flamegraph-profile-receiver-command`. `flamegraph.pl` is
optional; without it the runner still writes folded stacks.
