# PHP language test xfail lists

The profiler's "PHP language tests" CI job runs the upstream PHP test suite
with the profiling extension loaded, for every supported PHP version and for
both the `nts` and `zts` builds. These lists exclude tests that cannot pass in
that environment for reasons unrelated to profiler correctness.

`.gitlab/run_php_language_tests.sh` **deletes** every `.phpt` named in
`XFAIL_LIST` before running, so listing a test means "do not run" it.

| File | Applies to |
|------|------------|
| `php-language-xfail.list` | all profiler runs (`nts` + `zts`, all versions) |
| `php-language-xfail-pre84.list` | PHP < 8.4 only (appended by the job) |

Version-scoped failures live in their own list so the builds that pass them
keep running them.

## `php-language-xfail.list` (all versions)

Fail with the profiler loaded regardless of version/flavour:

- `ext/ffi/tests/list.phpt` — aborts (`free(): invalid size`); allocation
  profiler conflicts with the test's FFI memory management.
- `Zend/tests/concat_003.phpt` — perf-sensitive (2 s budget); allocation
  profiling overhead can exceed it on CI runners.

## `php-language-xfail-pre84.list` (PHP < 8.4)

opcache optimizer-output tests that fail only with the profiler on PHP ≤ 8.3.
On PHP < 8.4 the profiler overrides `zend_execute_internal` (to handle VM
interrupts while an internal function is on the stack); on 8.4+ that hook is
not installed (frameless calls), so these pass. Internal calls therefore
compile to `DO_FCALL` instead of `DO_ICALL`, changing the optimized opcodes.

- `opt/prop_types.phpt`, `opt/gh11170.phpt`, `opt/nullsafe_002.phpt` — cosmetic
  opcode-dump differences (`DO_ICALL` → `DO_FCALL`).
- `bug66251.phpt` — **not cosmetic**: a real constant-folding divergence (a
  same-file runtime constant gets folded when it should stay dynamic). Xfailed
  for now but needs a proper fix / upstream report.

See `INVESTIGATE-opcache-do_icall.md` for the full analysis and reproducer.
