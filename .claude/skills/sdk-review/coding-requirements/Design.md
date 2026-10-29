# Design

Where code belongs, who owns state, and API and type shape.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** report a finding when the diff matches a rule's `Flag:`
line and no `Not when:` applies. Cite the rule ID and propose the concrete
alternative.

## 1. Where code lives

### DES-1.1: Put tracer-only code in `tracer/` and keep `ext/` free of product logic
- Flag: tracer concerns added to shared `ext/` files (e.g. `ext/sidecar.c`),
  new `ext/` → product-directory dependencies, or a nested `#ifdef DDTRACE`
  inside an `ext/` block instead of guarding or moving the whole block.
- ✅ a new `tracer/ffe.c` rather than more code in `ext/sidecar.c`.

### DES-1.2: Put PHP-agnostic logic in libdatadog, not components-rs or C
- Flag: zend_string-agnostic helpers, protocol encoding (protobuf/varint),
  container-id parsing, or HTTP transports implemented in `components-rs/`
  or tracer C when they could be shared from libdatadog.

### DES-1.3: Fix shared-component bugs at the source, not with caller-side workarounds
- Flag: a tracer-side workaround for a libdatadog/sidecar bug (e.g. copying
  a map on every flush to dodge a deadlock instead of fixing lock order), or
  a fix to tracer code that libdatadog has already superseded.

### DES-1.4: Don't add a dependency for something a few lines of C do
- Flag: a new crate, libdatadog API, or build hack pulled in only to
  declare a struct or symbol the tracer could define itself.
- ✅ declare `otel_thread_ctx_v1` (non-`extern`) in `tracer/otel_context.c`.

### DES-1.5: Give new Rust code its own module file in the matching directory
- Flag: a feature's Rust code added to `components-rs/lib.rs`, or tracer
  modules placed outside `tracer/` (`tracer/mod.rs`).

## 2. Process boundaries

### DES-2.1: Do network I/O only in the sidecar, never in PHP processes
- Flag: HTTP/socket/curl calls from extension C/Rust or `src/` that bypass
  the sidecar.
- ✅ fetch in the sidecar and deliver like remote config (`ShmRemoteConfig`).

### DES-2.2: Never spawn threads in the extension
- Flag: `pthread_create`/`std::thread::spawn` in extension code; background
  work belongs in the sidecar.

### DES-2.3: Aggregate per thread in the extension, across processes in the sidecar
- Flag: per-event IPC to the sidecar where events could be batched, or large
  buffers sent to the sidecar other than as a fallback.
- ✅ aggregate in the extension or a shared-memory buffer; batch to the
  sidecar.

### DES-2.4: Use pushed remote config, don't poll it on hot paths
- Flag: a remote-config refresh/poll call on an evaluation or request path;
  the tracer is signalled when updates arrive.

## 3. State ownership

### DES-3.1: Keep one source of truth instead of synchronising copies
- Flag: state mirrored in two structures and kept in sync (an OTel context
  next to the root span, `meta`/`metrics` merged with attributes at
  serialization, a native staging layer for values PHP can write directly).
- ✅ embed the state in `ddtrace_root_span_data` and update it in place, or
  make one structure a view onto the other.

### DES-3.2: Tie per-root-span state to the root span
- Flag: a singleton that tracks "the current root id" to scope accumulated
  data.
- ✅ `ObjectKVStore::put(\DDTrace\root_span(), 'key', $accumulator)`

### DES-3.3: Use an in-band sentinel or natural width instead of side fields
- Flag: a separate length/flag field that only marks "unset", or a field
  widened beyond its source type (an `Option<i32>` stored as `i64`).
- ✅ `(uint64_t)-1` as "absent" for a 56-bit value.

## 4. Public API

### DES-4.1: Treat stubs, `src/api`, and `configuration.h` as public API
- Flag: a breaking change to a `*.stub.php` function, `src/api` class, or
  config key, or a superseded API not marked `@deprecated`.
- Not when: the function or config is marked internal (e.g.
  `DDTrace\Internal`).

### DES-4.2: Expose a public constructor with only user-facing parameters
- Flag: test-only factories (`createWithDependencies`) or constructor
  parameters that exist only to inject test doubles.
- ✅ `public function __construct(?LoggerInterface $logger = null)`; tests
  override internals via `Closure::call()`.

### DES-4.3: Throw on bad input to public API; fail soft on internal state
- Flag: silently ignoring invalid arguments to a direct public API call, or
  throwing from behaviour that depends on internal tracer state.

### DES-4.4: Expose magic values in stubs as named constants
- Flag: integer codes (e.g. expected-type `0`/`1`/`2`) documented only in
  prose and repeated as literals in PHP code.
- ✅ `const FFE_STRING = UNKNOWN;` with `@cvalue`, used by callers.

## 5. Build and CI

### DES-5.1: Add a `configure` switch instead of special-casing a build script
- Flag: behaviour hacked into `pecl-build` or similar scripts that CI and
  Makefiles could disable with an explicit `configure` option.

### DES-5.2: Pair every published CI resource with its cleanup
- Flag: images or artifacts published per commit/branch with no retention
  or deletion path, or deletion tied to PRs while publishing isn't.

## Don't flag
- Cross-dependencies between the C and Rust code; the hard layers are only
  extension API ↔ `src/` and components → ZAI → other extension code.
- Bespoke hooks instead of the generic hooking API, when non-invasive.
