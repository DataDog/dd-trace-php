# Debugging System Tests Locally (arm64)

Combines Python debugging (pytest `--pdb`) with gdb inside the weblog
container. For build/run instructions see
[ci/system-tests.md](ci/system-tests.md). For gdb fundamentals see
[gdb.md](gdb.md).

## arm64-specific build notes

On Apple Silicon, use `--platform linux/arm64` (native). amd64
emulation is too slow.

- Build images are multi-arch:
  `datadog/dd-trace-ci:php-<VER>_centos-7` works on arm64.
- `make` output goes to `extensions_aarch64/`,
  `standalone_aarch64/`.
- The `php_fpm_packaging` image has **no arm64 variant**. For
  `generate-final-artifact.sh` use `ubuntu:24.04` instead.
- Weblog images support arm64 natively.

## Building ddtrace.so with Rust linked

See [ci/building-locally.md](ci/building-locally.md#for-system-tests-centos-7-release-like-build)
for the build command, CARGO_HOME workaround, and `make` vs
`make static` explanation.

First build: ~20 min (Rust sidecar). Incremental (C-only): ~1 min.
The result is at
`~/.cache/dd-ci/systest-82/tmp/build_extension/modules/ddtrace.so`
(~85 MB, with debug symbols from `-g -O2`).

## Deploying via the .so override path

Place just the `.so` in system-tests binaries; the install script
downloads the latest GitHub release as base, installs it, and
replaces the installed `ddtrace.so`:

```bash
CACHE=~/.cache/dd-ci/systest-82
cp $CACHE/tmp/build_extension/modules/ddtrace.so \
   ~/repos/system-tests/binaries/ddtrace.so

# Remove any tarball/setup to trigger the download path
rm -f ~/repos/system-tests/binaries/dd-library-php-*.tar.gz
rm -f ~/repos/system-tests/binaries/datadog-setup.php

cd ~/repos/system-tests
WEBLOG_VARIANT=php-fpm-8.2 ./build.sh php
./build.sh -i proxy
```

Build log should show:
```
Overriding package ddtrace.so with custom binary from /binaries/ddtrace.so
Found installed ddtrace.so at /usr/lib/php/20220829/ddtrace.so, replacing
```

## Container lifecycle: when they're alive

**Important:** system-tests stops containers and collects all data
BEFORE running test assertions (in `post_setup`, called at the end
of `pytest_collection_finish`). By the time `--pdb` pauses on a
test failure, containers are already gone.

This means:
- **pdb on failure** — you inspect offline data only, no new
  requests
- **gdb** — you need `--sleep` mode to keep containers alive

## Python debugging with pytest --pdb

Run in tmux for interactive pdb:

```bash
tmux new-session -d -s systest \
  "cd ~/repos/system-tests && \
   WEBLOG_VARIANT=php-fpm-8.2 ./run.sh DEFAULT \
   tests/test_semantic_conventions.py::Test_Meta::test_meta_http_status_code \
   -v -s --pdb"
```

Wait for the pdb prompt, then interact:

```bash
# query what tags the span has
tmux send-keys -t systest \
  "[k for k in span['meta'] if 'status' in k]" Enter
sleep 1
tmux capture-pane -t systest -p | tail -5
```

Example output:
```
(Pdb) [k for k in span['meta'] if 'status' in k]
['http.response.status_code']
```

To set Python breakpoints before a specific point, add
`breakpoint()` in the test source (in the system-tests checkout).

## gdb inside the weblog container

Use `--sleep` to keep containers alive indefinitely:

```bash
cd ~/repos/system-tests
WEBLOG_VARIANT=php-fpm-8.2 ./run.sh DEFAULT --sleep
```

No tests run; the full scenario (weblog, proxy, agent) stays up
until Ctrl-C. Container name: `system-tests-weblog`.

### Install gdb + tmux (first time)

```bash
docker exec system-tests-weblog \
  bash -c 'apt-get update -qq && \
    apt-get install -yqq gdb tmux procps 2>/dev/null'
```

### Attach gdb via tmux

```bash
# Find a PHP-FPM worker PID (not the master)
docker exec system-tests-weblog ps aux | grep 'php-fpm.*pool'
#  www-data  129  ...  php-fpm: pool www

# Attach
docker exec system-tests-weblog \
  tmux new-session -d -s gdb \
  "gdb -q -iex 'set pagination off' \
    -iex 'set confirm off' -p 129"

sleep 3
docker exec system-tests-weblog \
  bash -c 'tmux capture-pane -t gdb -p' | tail -5
```

### Set breakpoints and trigger

```bash
docker exec system-tests-weblog \
  bash -c "tmux send-keys -t gdb \
    'break dd_set_entrypoint_root_span_props' Enter"
sleep 1
docker exec system-tests-weblog \
  bash -c "tmux send-keys -t gdb 'continue' Enter"

# Trigger (may need several requests, see FPM selection below)
for i in $(seq 1 5); do
  curl -s http://localhost:7777/ >/dev/null
done

# Check
docker exec system-tests-weblog \
  bash -c 'tmux capture-pane -t gdb -p' | tail -15
```

Expected:
```
Thread 1 "php-fpm8.2" hit Breakpoint 2,
  dd_set_entrypoint_root_span_props (data=..., span=...)
    at .../ext/serializer.c:609
```

### FPM worker selection

PHP-FPM round-robins between workers. With gdb attached, one
worker may be frozen in `accept()`, causing FPM to route all
requests to the other. Solutions:

- Send multiple requests until one hits the attached worker.
- Attach gdb to both workers.
- Configure FPM: `docker exec system-tests-weblog bash -c
  "echo 'pm.max_children = 1' >> /etc/php/8.2/fpm/pool.d/www.conf
  && kill -USR2 1"` to use a single worker.

### Stepping and inspecting

```bash
# Backtrace
docker exec system-tests-weblog \
  bash -c "tmux send-keys -t gdb 'bt 5' Enter"
sleep 1
docker exec system-tests-weblog \
  bash -c 'tmux capture-pane -t gdb -p' | tail -15

# Step
docker exec system-tests-weblog \
  bash -c "tmux send-keys -t gdb 'next' Enter"
```

### Inlined functions at -O2

With `-O2`, some `static` functions (e.g.,
`dd_set_entrypoint_root_span_props_end`) are inlined and won't
appear in `info functions`. Use line breakpoints instead:

```gdb
break serializer.c:1179
```

Or rebuild with `-O0` for full symbol visibility.

### Sidecar (Rust) debugging

See [gdb.md](gdb.md) "Attaching to the sidecar" section. Key:
- `file /proc/<pid>/exe` before `attach`
- `set language rust` before Rust breakpoints
- `pgrep -f 'datadog-ipc.*ddog_daemon_entry_point'` for the PID.

## Inspecting test data after a run

Trace data in `logs/interfaces/library/`:

```bash
python3 -c "
import json, glob
for f in sorted(glob.glob('logs/interfaces/library/*traces*.json')):
    data = json.load(open(f))
    for trace in data.get('request',{}).get('content',[]):
        for span in trace:
            meta = span.get('meta', {})
            if any('status' in k for k in meta):
                tags = {k:v for k,v in meta.items() if 'status' in k}
                print(f, tags)
"
```

PHP/tracer logs:

```
logs/docker/weblog/logs/tracer.log      ← tracer LOG(ERROR, ...) output
logs/docker/weblog/logs/php_error.log   ← php_log_err() / error_log() / trigger_error() output
logs/docker/weblog/logs/appsec.log      ← appsec extension mlog() output
logs/docker/weblog/logs/helper.log      ← appsec C++ helper SPDLOG_*() output
logs/docker/weblog/logs/apache2/error.log  ← profiler error!() output (if log level enabled)
```

### Adding debug output

Use the project's own logging macros — they route to the collected log files
above. All verified empirically on the `apache-mod-8.2` weblog.

**Reliable methods:**

| Component | Macro | Lands in |
|---|---|---|
| Tracer (C) | `LOG(ERROR, "fmt", args)` | `tracer.log` |
| Tracer (C) | `php_log_err("msg")` | `php_error.log` |
| Appsec extension (C) | `mlog(dd_log_error, "fmt", args)` | `appsec.log` + `php_error.log` |
| Appsec C++ helper | `SPDLOG_ERROR("fmt", args)` | `helper.log` |
| Appsec Rust helper | `log::error!("fmt", args)` | `helper.log` (only when Rust helper is active) |
| Profiler (Rust) | `error!("msg")` | `apache2/error.log` (requires `datadog.profiling.log_level=error`) |

**Methods that lose output** (verified with gdb):

- **`fprintf(stderr, ...)`** — lost due to stdio buffering. `stderr` is fully
  buffered (fd 2 points to a file, not a tty). Output sits in the `FILE*`
  buffer and never reaches disk before system-tests stops the containers.
  Confirmed: calling `fflush(stderr)` from gdb made the output appear.

- **`trigger_error(E_USER_NOTICE)`** — silently dropped when `log_errors=Off`
  (the weblog default). Works after adding `log_errors=On` to `php.ini`.

- **Profiler `error!()`** — silently filtered when `datadog.profiling.log_level`
  is `off` (the default). Works after setting `datadog.profiling.log_level=error`
  in `php.ini`.
