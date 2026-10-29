# Correctness

Bugs, races, unhandled edge cases, and tests that pass when they shouldn't.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** report a finding when the diff matches a rule's `Flag:`
line and no `Not when:` applies. Cite the rule ID, location, and the failing
input or interleaving.

## 1. Root cause

### COR-1.1: Fix how an invalid state arises instead of guarding against it
- Flag: a NULL check, retry, or "retire"/skip step added to stop a crash or
  flake, where the PR doesn't explain how the invalid state is reached and
  adds no reproducer test.
- Not when: the value can legitimately be absent by contract (e.g. the
  sidecar connection failed and the caller handles that case explicitly).
- ❌ `if (!DDTRACE_G(sidecar)) { return; } // probes installed too early`
- ✅ don't install probes until the sidecar is initialised; skip them if
  connecting failed.

### COR-1.2: Wire a feature into its production caller in the same PR
- Flag: a function documented as "called in production" (or by the sidecar)
  that only tests call, or a new component that lands with no consumer.

## 2. Threads, ZTS and fork

### COR-2.1: Make new global state ZTS-safe from the start
- Flag: a `static` global (HashTable, cache, Rust `static Mutex`) written
  outside MINIT/MSHUTDOWN without `TSRM_TLS`, module globals, or a mutex; or
  ZTS support deferred to a follow-up.
- Not when: the state is only written in MINIT/MSHUTDOWN/GINIT.
- ❌ `static HashTable routing_cache;`
- ✅ `static TSRM_TLS HashTable routing_cache;` (or a `DDTRACE_G()` field)

### COR-2.2: Don't add atomics or mutexes to thread-local state, or expect a lone atomic store to fix a race
- Flag: a mutex/atomic on state only its own PHP thread touches; or
  `atomic_store` replacing a plain assignment with no ordering relation to
  the other accesses. Check that mutexes on global state won't be contended
  by hundreds of request threads.

### COR-2.3: Re-establish per-process state in the child after fork
- Flag: process-scoped state (`MFD_CLOEXEC` mappings, published contexts,
  runtime ids) not refreshed in `datadog_internal_handle_fork()`, or
  refreshed only under an unrelated condition.
- ❌ `if (runtime_id_changed) { datadog_publish_otel_process_context(...); }`
- ✅ `datadog_publish_otel_process_context(...);` (put conditions inside it)

## 3. Request and span state

### COR-3.1: Treat runtime-changeable config as per-request, not per sidecar session
- Flag: a value changeable per request (`DD_SERVICE`, `DD_ENV`,
  `DD_VERSION`, anything settable via `ini_set`) stored once per sidecar
  session or cached for the process lifetime.

### COR-3.2: Update span/stack-derived state on every switch, close, and drop path
- Flag: span id or OTel context updates applied only when
  `stack == DDTRACE_G(active_stack)`, or run before a
  `ddtrace_switch_span_stack()` that follows; mirror `dd_drop_span`'s
  structure.

### COR-3.3: Mask bit fields instead of overwriting them
- Flag: assigning one flag to a multi-bit field (`trace_flags`, sampling
  bits).
- ❌ `span->trace_flags = DDTRACE_TRACE_FLAG_RANDOM;`
- ✅ `span->trace_flags = (span->trace_flags & ~DDTRACE_TRACE_FLAG_RANDOM) | (random ? DDTRACE_TRACE_FLAG_RANDOM : 0);`

### COR-3.4: Record derived metadata only when the source value really changed
- Flag: a source/override marker (e.g. `_dd.svc_src`) set without an
  `is_identical` check, set unconditionally in the serializer, or keyed on
  an unrelated condition (e.g. comparing with the root span's service).

## 4. Zend engine semantics

### COR-4.1: Keep refcount semantics when swapping assignment, dup, and copy
- Flag: a plain zval assignment (no addref) replaced by a dup/`ZVAL_COPY`,
  or the reverse, without adjusting the matching release.

### COR-4.2: Handle integer keys when iterating user-supplied arrays
- Flag: `ZEND_HASH_FOREACH_STR_KEY_VAL` over user data; PHP turns keys like
  `"1234"` into integer keys, which this loop skips.
- ✅ `ZEND_HASH_FOREACH_KEY_VAL(ht, num_key, str_key, val)`, converting
  `num_key` when `str_key` is NULL.

### COR-4.3: Guard recursive walks of user arrays and objects
- Flag: recursive serialization or copying of zvals without a
  `GC_PROTECT_RECURSION`/`Z_IS_RECURSIVE` check and a self-referencing test.

### COR-4.4: Check engine-level tricks against the VM mode and new PHP behaviour
- Flag: interceptor/hook code that returns VM-specific values (e.g.
  `ZEND_VM_ENTER_BIT`) without checking the tailcall VM is in use, or that
  attaches state to Closures/oplines PHP may share (PHP 8.6 closure
  deduplication).

## 5. Tests

### COR-5.1: Keep tests parsable on PHP 7.0
- Flag: `void`/nullable return types, flexible heredoc (closing marker
  followed by `);`), or other post-7.0 syntax in a test not restricted to
  newer versions by `--SKIPIF--`.
- ❌ `function foo(): void {}`
- ✅ `function foo() {}`

### COR-5.2: Isolate tests from the runner environment and shared agent state
- Flag: a test that depends on env vars the test runner also sets (e.g.
  `DD_ENTITY_ID`), or a `.phpt` test using the request-replayer without
  `datadog.trace.agent_test_session_token`.

## Don't flag
- Missing synchronisation on state only touched in MINIT/MSHUTDOWN.
- Tests that send to the local request-replayer or depend on wall clock in
  end-to-end checks, provided they are isolated (COR-5.2).
