# gRPC profiler crash plan

## Goal

Create one deterministic PHPT that reproduces the cross-thread `StringSet`
use-after-free. Then fix NTS by following PHP's global model instead of using
Rust TLS.

## Phase 1: Reproduce the crash

### 1. Use the production runtime-cache path

Update `.gitlab/generate-profiler.php`:

- Remove `stack_walking_tests` from the profiler extension used by PHPT.
- Keep `stack_walking_tests` enabled for Cargo unit tests.
- This ensures the PHPT exercises the real Zend runtime-cache implementation.

### 2. Add a test-only PHP helper

Update `profiling/src/php_ffi.c` with an NTS-only, `CFG_TEST` helper:

```php
Datadog\Profiling\run_on_native_thread(callable $callback): void
```

The helper will:

1. Enable runtime caching for this CLI test process.
2. Prepare the callable with `zend_fcall_info`.
3. Create a pthread.
4. Call the PHP callback with `zend_call_function()` on that pthread.
5. Join the pthread.
6. Return to the main PHP thread.

This matches the relevant ext-grpc behavior without depending on ext-grpc.

### 3. Add one PHPT

Add `profiling/tests/phpt/native_thread_wall_time_01.phpt`.

Test setup:

- NTS only.
- PHP 8.0+, where profiler runtime caching is enabled.
- Profiler enabled.
- Wall-time profiling enabled.
- Allocation profiling explicitly disabled.
- Test helper required.

Test body:

```php
function sampled_callback(): void
{
    for ($i = 0; $i < 100; $i++) {
        usleep(10_000);
    }
}

Datadog\Profiling\run_on_native_thread('sampled_callback');
sampled_callback();

echo "Done.\n";
```

Expected failure before the fix:

1. The foreign thread receives a wall-time sample.
2. It caches pointers owned by its TLS `StringSet`.
3. The pthread exits and destroys that `StringSet`.
4. The main thread runs the same `zend_function`.
5. A wall-time sample reads the stale runtime-cache pointer.
6. PHP segfaults in `ThinStr::len` or `StringSet::get_thin_str`.

### 4. Prove the reproduction

Run the PHPT repeatedly, for example 10 times.

Success criteria:

- It crashes reliably on current `master`.
- The stack contains:
  - `ThinStr::len`
  - `StringSet::get_thin_str`
  - `handle_function_cache_slot`
  - `collect_time`
- It crashes with allocation profiling disabled.
- The existing `native_thread_alloc_01.phpt` remains green.

Stop after this phase and report the result before changing production code.

## Phase 2: Fix NTS ownership

### 1. Follow PHP's global model

Change cached string ownership:

- **NTS:** store one `StringSet` in profiler/PHP globals.
- **ZTS:** keep the existing Rust thread-local `StringSet`.

Prefer adding the NTS state to `ProfilerGlobals`, matching allocation
profiling's existing approach.

### 2. Preserve current behavior

Keep:

- Existing runtime-cache slots.
- Existing `StringSet` interning.
- Existing request-shutdown size check and reset.
- Thread-local cache statistics if they do not escape their thread.

Do not add:

- A thread check on every sample.
- A mutex in NTS.
- Special ext-grpc detection.
- ZTS support for foreign threads.

### 3. Why this should fix it

In NTS, both these objects then follow PHP's process-global model:

- Zend function runtime-cache slots.
- The `StringSet` owning the cached strings.

A native thread exiting no longer destroys the memory referenced by Zend's
cache.

## Phase 3: Verify the fix

Run:

1. New wall-time foreign-thread PHPT:
   - crashes before the fix
   - prints `Done.` after the fix
2. Existing allocation foreign-thread PHPT:
   - remains green
3. Full profiler PHPT suite:
   - NTS
   - ZTS
4. Profiler Cargo tests.
5. Stack-walking benchmarks to check for regressions.
6. The real ext-grpc reproduction from commit `992d052` once as final
   confirmation.

## Non-goals

- Making ext-grpc foreign callbacks safe on ZTS.
- Supporting concurrent PHP execution in NTS.
- Adding ext-grpc as a test dependency.
- Fixing unrelated profiler TLS usage.
