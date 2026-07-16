# Debugging with jdb + gdb in appsec integration tests
## General ad

## Overview

The appsec Gradle integration tests run inside Docker containers. Use
`--debug-jvm` with Gradle to pause the JVM for a Java debugger (jdb), then
attach gdb to processes inside the container (PHP-FPM workers, sidecar).

See [gdb.md](gdb.md) for gdb-specific instructions.
See [ci/appsec-gradle-integration.md](../ci/appsec-gradle-integration.md) for
Gradle test details.

## Workflow

```
Gradle (--debug-jvm) -> jdb (port 5005) -> controls test flow
                                           |
                                           v
                              Docker container
                              ├── PHP-FPM worker (attach gdb for PHP/C code)
                              └── sidecar (attach gdb for Rust code)
```

### Step-by-step

1. **Start Gradle** with `--debug-jvm`:
   ```bash
   ./gradlew test8.3-debug --info --tests "*TestClass*" --debug-jvm 2>&1 | tee /tmp/debug.log &
   ```

2. **Connect jdb** and set breakpoints:
   ```bash
   tmux new-session -d -s jdb "jdb -connect com.sun.jdi.SocketAttach:hostname=localhost,port=5005"
   ```

3. **Set breakpoints at the right moments** (see "Breakpoint strategy" below).

4. **When jdb is paused**, attach gdb inside the container, set gdb
   breakpoints, continue gdb.

5. **Resume jdb** to trigger the action you want to observe.

6. **Inspect** in gdb when it stops.

## Breakpoint strategy — the critical part

### Rule: prepare gdb breakpoints BEFORE the triggering action

The most common mistake is setting gdb breakpoints **after** the event of
interest has already happened. The correct pattern:

1. Use jdb to pause the test **before** the code that triggers the behavior
   you want to observe.
2. While jdb is paused, attach gdb and set your breakpoints.
3. Continue gdb.
4. **Then** resume jdb to let the triggering action happen.

### Example: capturing telemetry endpoint HTTP requests

Wrong approach: break at `waitForAppEndpoints` (line 142) — endpoints already
collected by then, and they'll be sent soon. If we're trying to stop at the
moment they're sent, we may not be fast enough.

Instead, break after there's been a request that has started sidecar, but has
not triggered the desired behavior -- you can introduce such a request for
testing if necessary.

### Keeping the container alive

The container is torn down when the test exits (pass or fail). Common causes
of premature container death:

- **Test timeout**: `waitForAppEndpoints` has a 30s timeout. If the sidecar
  is frozen by gdb and can't respond, the test's HTTP requests time out,
  `waitForAppEndpoints` throws, and the container dies.
- **Test completion**: if jdb doesn't have another breakpoint after the
  current one, the test finishes and tears down.

**Mitigations:**
- Always set a **second jdb breakpoint** after the section you're
  investigating (e.g., at the assert after `waitForAppEndpoints`) so the
  test pauses before finishing.
- Use **non-stopping gdb breakpoints** (Python breakpoints that
  auto-continue) so the sidecar keeps running while you filter for the
  right event.
- Don't block the sidecar for extended periods — it needs to respond to IPC
  from PHP and HTTP from the telemetry flush.

## Sidecar watchdog

The sidecar has a watchdog thread (`datadog-sidecar/src/watchdog.rs`) that
checks a `still_alive` counter every 10 seconds. If the counter hasn't
changed for two intervals (~20s), it calls `abort()`. When gdb stops the
sidecar, the tokio runtime freezes but the watchdog thread keeps running.

**Workaround**: temporarily patch the watchdog to disable the abort:
```rust
// In watchdog.rs, replace the abort block:
if maybe_stuck {
    // watchdog disabled for debugging
}
```
Then rebuild the tracer (`docker volume rm php-tracer-8.3-debug` to force).

Remember to revert the patch after debugging.

## Groovy line numbers in jdb

Groovy compiles to different bytecode line numbers than the source. When
setting `stop at Class:LINE`:
- Some lines have no code (`No code at line N`).
- Try nearby lines (e.g., 162 instead of 161).
- The first line with executable code after a method declaration usually
  works.
