# Maintainability

Duplication, dead code, comments, naming, and test value.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** report a finding when the diff matches a rule's `Flag:`
line and no `Not when:` applies. Cite the rule ID and point to the existing
helper or simpler shape.

## 1. Dead and redundant code

### MAINT-1.1: Remove leftovers after a refactor
- Flag: functions, classes, config knobs, CI files or Dockerfiles that the
  change made unused (e.g. a refresh helper left after removing its only
  caller), or new code that nothing calls.

### MAINT-1.2: Remove checks the types or callers already guarantee
- Flag: NULL checks before `zend_string_release` on always-set pointers,
  `is_array()` on a typed return, interned checks before
  `zend_string_addref`/`release`, `catch_unwind` under `panic = "abort"`,
  redundant `ZVAL_UNDEF` init, or a condition implied by the next one.
- ❌ `if (e->key) { zend_string_release(e->key); }`
- ✅ `zend_string_release(e->key);`

### MAINT-1.3: Inline trivial wrappers and single-use indirections
- Flag: a one-line helper around an API already at hand, a struct or class
  that only forwards to another, or a file split (`.tpl`, extra `.rs`) with
  one user.
- ❌ `dd_del_root_tag(span, key);`
- ✅ `zend_hash_del(attributes, key);`

### MAINT-1.4: Use `ZEND_ASSERT` for states callers can't produce
- Flag: an `if` guarding against a state every caller rules out (e.g. an
  active span without a root).
- ✅ `ZEND_ASSERT(span->root && "An active span without a root is broken");`

## 2. Duplication

### MAINT-2.1: Move repeated sequences into the shared callee
- Flag: the same snippet added to several callbacks or integrations instead
  of the common function (`dd_alter_prop`, one integration helper, the
  evaluator rather than each of its callers).

### MAINT-2.2: Complete a migration across every site
- Flag: an API migration (e.g. `->meta` to attributes) applied to some
  call sites while others in the same or sibling files keep the old form.

### MAINT-2.3: Use existing formatting and stdlib functions instead of hand-rolling
- Flag: manual hex encoding, manual UTF-8 character counting, or
  `smart_str_appendl` with `ZSTR_VAL`/`ZSTR_LEN`.
- ❌ a hand-rolled 56-bit hex loop
- ✅ `smart_str_append_printf(&s, "rv:%014" PRIx64, rv);`,
  `smart_str_append(&s, str);`, `preg_match("(.{,$n})u", $v, $m)`

## 3. Comments

### MAINT-3.1: Trim comments to the essential why
- Flag: comments that narrate history ("split from X in #N"), over-explain
  cases not worth caring about, or take a paragraph to say one sentence.

### MAINT-3.2: Fix false or stale comments and keep them on the line they describe
- Flag: comments describing old behaviour (e.g. "<= 8.2 in-process
  sender"), wrong mechanisms, guarantees the code doesn't need, or a
  comment moved away from the attribute or line it explains.

## 4. PHP types

### MAINT-4.1: Declare parameter types instead of casting inside the body
- Flag: untyped parameters followed by `(int)`/`(bool)` casts.
- ❌ `function addSerialId($id) { $id = (int) $id; ... }`
- ✅ `function addSerialId(int $id) { ... }`

## 5. Tests

### MAINT-5.1: Test with real spans instead of seams in production code
- Flag: production code paths that exist only to emulate root spans or
  isolate tests.
- ✅ `datadog.trace.generate_root_span=0` and start root spans manually;
  `TracerTestTrait::isolateTracer()`.

### MAINT-5.2: Update tests to the new model instead of adding compat shims
- Flag: test helpers or extension test functions kept in the old shape
  (e.g. separate meta/metrics getters) via a shim instead of migrating the
  tests.

### MAINT-5.3: Normalise volatile values in the shared test parser
- Flag: per-test normalisation of ids or values that
  `parseMultipleRequestsFromDumpedData` (or another shared helper) could do.

### MAINT-5.4: Delete tests that cover removed features or can't catch their regression
- Flag: new tests for deprecated/no-op features, tests named for an
  unrelated variant (e.g. "V1 wire" for wire-agnostic behaviour), or tests
  that would still pass if the guarded code were forgotten.

### MAINT-5.5: Keep test output human-readable
- Flag: rewriting a test's expected output to opaque data (e.g. only hex
  dumps) and dropping the readable form.

## Don't flag
- Magic constants (buffer sizes, `PHP_VERSION_ID` comparisons, bit masks)
  or version-specific code (e.g. `compatibility.h`) without comments.
- Long functions; size alone is not a reason to split.
- `TODO` comments without a ticket; they document known limitations.
- Missing changelog or release-note entries.
- Missing error propagation or "silent" error handling in C code.
