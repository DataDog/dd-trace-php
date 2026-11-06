# Performance

Allocations and CPU cost on hot paths (span creation and finish, tagging,
propagation, instrumentation callbacks for tracing, similar paths for 
other products), at startup and shutdown, on error and diagnostic paths,
and in tooling.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** report a finding when the diff matches a rule's `Flag:`
line, wherever the code runs: hot paths, startup and shutdown, error and
diagnostic paths, CI and build configuration, and test infrastructure. Don't
dismiss a match because each call is cheap, the path looks rare, or the
commit message explains the choice. Cite the rule ID, say which path it is
on and how often it runs, and let the severity reflect the actual cost.

## 1. Hot paths

### PERF-1.1: Extract promoted keys once instead of comparing every key during serialization
- Flag: string comparisons against special keys inside the meta/attributes
  iteration loop of the serializer.
- ✅ `zend_hash_str_find` + `zend_hash_str_del` the promoted keys up front,
  then serialize them from span properties.

### PERF-1.2: Parse in the existing single pass and store parsed fields
- Flag: a second normalization pass that rewrites a header string (e.g.
  tracestate) already walked once; store parsed values on the span and
  rebuild at format time.

### PERF-1.3: Compute once what doesn't change per call
- Flag: re-registering telemetry metrics on every flush, rebuilding tag
  vectors per sample, re-encoding values per evaluation, or recomputing a
  hash that `datadog_process_tags_get_base_hash()` already provides.

### PERF-1.4: Pass direct pointers across FFI, not index paths
- Flag: FFI that returns `usize` indexes requiring payload → chunk → span →
  link lookups on every call.

### PERF-1.5: Bound caches keyed by application data
- Flag: a cache or table keyed by routes, URLs or other app values with no
  size limit or eviction (e.g. a route normalization cache without LRU).

## 2. Strings and zvals

### PERF-2.1: Pass `zend_string*` instead of splitting into `char*` + length
- Flag: a `zend_string` split into pointer/length only to be recreated
  later.
- ✅ pass the `zend_string*` and `zend_string_copy()` to keep it.

### PERF-2.2: Add a reference instead of copying into a temporary zval
- Flag: `ZVAL_COPY` into a temporary, or `zval_add_ref` copying, where the
  value can be moved or shared.
- ❌ `ZVAL_COPY(&tmp, parent_val); zend_hash_str_update(ht, ZEND_STRL("k"), &tmp);`
- ✅ `Z_TRY_ADDREF_P(parent_val); zend_hash_str_update(ht, ZEND_STRL("k"), parent_val);`

### PERF-2.3: Return `ZSTR_EMPTY_ALLOC()` for empty strings
- ❌ `return zend_string_init("", 0, 0);`
- ✅ `return ZSTR_EMPTY_ALLOC();`

### PERF-2.4: Avoid deep copies of large or frequent strings
- Flag: building an owned Rust `String`/`Vec` from a `CharSlice` (or
  duplicating a `zend_string`) on a per-span or per-call path where a
  borrow would do.

## 3. Process lifetime

### PERF-3.1: Never block shutdown on the sidecar or the telemetry thread
- Flag: `#[blocking]` sidecar calls in MSHUTDOWN, or joining/synchronising
  with the telemetry thread on exit; short-lived CLI processes must not
  wait.
- ✅ a mutex-guarded "shutting down" flag the other side checks.

### PERF-3.2: Treat startup cost like request latency
- Flag: eager initialisation or transformation at MINIT/RINIT that could be
  lazy; CLI processes pay it on every run.

## Don't flag
- Rust FFI crossings or calls to extension functions as expensive; both are
  cheap (and cheaper than plain PHP code).
- Shallow copies; only deep copies of large data matter (PERF-2.4).
- TLS / `DDTRACE_G()` lookups.
