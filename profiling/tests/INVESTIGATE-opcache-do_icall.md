# INVESTIGATE: opcache optimizer differences with the profiler (PHP < 8.4)

Status: **xfailed for PHP ≤ 8.3** (see `php-language-xfail-pre84.list`). This
file records what we know so it can be debugged properly later.

## Affected tests (PHP language tests job, profiler loaded, PHP ≤ 8.3)

- `ext/opcache/tests/opt/prop_types.phpt`
- `ext/opcache/tests/opt/gh11170.phpt`
- `ext/opcache/tests/opt/nullsafe_002.phpt`
- `ext/opcache/tests/bug66251.phpt`

All four **pass** on PHP 8.4 ZTS with the profiler, and **pass** on every
version without the profiler. They only fail with the profiler on PHP ≤ 8.3.

## Why it is PHP ≤ 8.3 only

The wall-time profiler overrides the global `zend_execute_internal`
(`profiling/src/wall_time.rs`, `mod execute_internal`) so it can handle a
pending VM interrupt while an internal function (e.g. `sleep`, `curl_exec`)
is still on top of the call stack — otherwise that time is misattributed to
whatever runs next.

That `mod` is gated:

```rust
#[cfg(not(php_frameless))]
mod execute_internal { ... pub unsafe fn minit() { zend_execute_internal = Some(execute_internal); } }
```

`php_frameless` is set for PHP 8.4+ (frameless internal calls; see
php-src PR 14627, "Levi changed this in 8.4"), so the hook is **only installed
on PHP < 8.4**. On 8.4+ the engine processes the interrupt itself, the hook is
not needed, and `zend_execute_internal` stays NULL.

Note: `zend_execute_ex` is **not** hooked by the profiler (verified — only the
`Generator::throw()` *method handler* is wrapped in `exception.rs`; the
`prev_execute_data` there is a struct field, not the execute hook). So user
function dispatch (`DO_UCALL`) is unaffected; only internal calls are.

## Group 1 — `DO_ICALL` → `DO_FCALL` (cosmetic; prop_types, gh11170, nullsafe_002)

These tests dump optimized opcodes (`opcache.opt_debug_level`) and assert that
calls to internal functions (`rand`, `var_dump`, …) compile to `DO_ICALL`.

The compiler only emits `DO_ICALL` when `zend_execute_internal` is NULL
(`Zend/zend_compile.c`, `zend_get_call_op`):

```c
if (fbc->type == ZEND_INTERNAL_FUNCTION && !(CG(compiler_options) & ZEND_COMPILE_IGNORE_INTERNAL_FUNCTIONS)) {
    if (init_op->opcode == ZEND_INIT_FCALL && !zend_execute_internal) {   // <-- gate
        if (!(fbc->common.fn_flags & ZEND_ACC_DEPRECATED)) {
            return ZEND_DO_ICALL;
        }
    }
}
...
return ZEND_DO_FCALL;
```

Because the profiler sets `zend_execute_internal`, the engine must route
internal calls through the generic `DO_FCALL` (which honors the hook);
`DO_ICALL` would call the handler directly and bypass it. So the optimized
opcodes legitimately differ. This is the same behavior as ddtrace and
DTrace-enabled PHP builds. It fires even with `DD_PROFILING_ENABLED=0` because
the hook is installed at MINIT unconditionally.

**Verdict:** harmless opcode-dump mismatch. Nothing to fix; just xfail on ≤8.3.

## Group 2 — `bug66251.phpt`: a REAL behavioral divergence (needs debugging)

This is **not** cosmetic. Test body:

```php
<?php
printf("A=%s\n", getA());   // called before A is defined
const A = "hello";
function getA() { return A; }
```

Correct behavior (the whole point of bug #66251): opcache must NOT fold the
same-file runtime constant `A` into `getA()`. At the time `getA()` runs, `A`
is not yet defined → `Fatal error: Undefined constant "A"`.

With the profiler on PHP ≤ 8.3, opcache **folds** `A` → the program prints
`A=hello` instead of throwing. That is a semantic change, not just an opcode
dump.

### It is not a test-harness artifact

run-tests.php exposes it because it sets `opcache.file_update_protection=0`,
so the freshly generated test file is cached immediately. But that is not a
test-only condition — **any file older than 2 s (the default) gets cached the
same way**. Reproduced with default opcache settings on an aged file:

```sh
# profiler loaded via conf.d; OC = path to opcache.so
printf '<?php\nprintf("A=%%s\\n", getA());\nconst A="hello";\nfunction getA() {return A;}\n' > /tmp/b.php
touch -d "1 hour ago" /tmp/b.php

php -d zend_extension=$OC -d opcache.enable=1 -d opcache.enable_cli=1 \
    -d opcache.optimization_level=-1 -f /tmp/b.php
# profiler + PHP<=8.3  -> "A=hello"   (WRONG: constant folded)
# no profiler          -> Fatal error: Undefined constant "A"   (correct)
# PHP 8.4+ (any)       -> Fatal error: Undefined constant "A"   (correct)
```

Minimal trigger matrix (PHP 8.3 ZTS):

| profiler | opcache.file_update_protection | result |
|---|---|---|
| off | 0 | fatal (correct) |
| on  | 2 (default), fresh file | fatal (correct; file too new to cache) |
| on  | 0, or default + aged file | **A=hello (wrong)** |

So: profiler loaded **and** opcache actually caching the file → constant
folded.

### Open question / where to dig next

Why does loading the profiler make opcache fold a constant it otherwise
defers? The profiler's only relevant engine change on ≤8.3 is the
`zend_execute_internal` override (which turns internal calls into `DO_FCALL`)
plus being registered as a `zend_extension`. Hypothesis: the
`DO_UCALL`/`DO_ICALL` → `DO_FCALL` change alters opcache's call-graph / SCCP
analysis enough that the deferred-constant guard from bug #66251 no longer
triggers, so SCCP substitutes `A`. Needs confirmation by:

1. Dumping `getA()`'s optimized opcodes with/without the profiler under
   `opcache.file_update_protection=0` (the difference will be a folded
   `RETURN string("hello")` vs a `FETCH_CONSTANT A` + `RETURN`).
2. Bisecting which opcache optimizer pass (SCCP / DFA / pass1 constant
   propagation) does the fold, and whether it keys off the call opcode.
3. Checking whether ddtrace (also overrides `zend_execute_internal` on ≤8.3)
   reproduces — if so this is a general "VM-hook + opcache" issue, not
   profiler-specific.

This likely affects real programs that reference a constant before its
same-file definition (uncommon, but the divergence is real).

## Reproducing the whole set

```sh
# 8.3 ZTS image, profiler built into /tmp/cargo, loaded via conf.d profiling.ini
cd /usr/local/src/php
php run-tests.php -q -p /usr/local/bin/php \
  ext/opcache/tests/opt/prop_types.phpt \
  ext/opcache/tests/opt/gh11170.phpt \
  ext/opcache/tests/opt/nullsafe_002.phpt \
  ext/opcache/tests/bug66251.phpt
# all 4 FAIL with profiler on <=8.3; all pass without profiler or on 8.4+
```
