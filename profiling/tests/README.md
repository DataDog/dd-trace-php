# PHP language test xfail lists

The profiler's "PHP language tests" CI job runs the upstream PHP test suite
with the profiling extension loaded, for every supported PHP version and for
both the `nts` and `zts` builds. These lists exclude tests that cannot pass in
that environment for reasons unrelated to profiler correctness.

`.gitlab/run_php_language_tests.sh` **deletes** every `.phpt` named in
`XFAIL_LIST` before running, so listing a test means "do not run" it.

| File | Applies to |
|------|------------|
| `php-language-xfail.list` | all profiler runs (`nts` + `zts`) |
| `php-language-xfail-zts.list` | ZTS build only (appended by the job) |

ZTS-only failures live in their own list so the NTS build keeps running them.

## `php-language-xfail.list`

Fail with the profiler loaded on both NTS and ZTS:

- `ext/ffi/tests/list.phpt` — aborts (`free(): invalid size`); allocation
  profiler conflicts with the test's FFI memory management.
- `Zend/tests/concat_003.phpt` — perf-sensitive (2 s budget); allocation
  profiling overhead can exceed it on CI runners.

## `php-language-xfail-zts.list`

All entries pass on NTS and fail only on ZTS, and reproduce on vanilla ZTS PHP
with no Datadog extension loaded — so none are profiler defects.

Root cause: on ZTS, PHP flushes its `PHP Startup` diagnostics *after* the SAPI
starts emitting request output; on NTS they appear before it. The bundled
`--EXPECTF--` was written for the NTS ordering, so the diff is purely the
position of the startup notice. The tracer language-test job only runs NTS, so
it never hits these.

The relocated diagnostic is either a `Deprecated:` notice (`assert.*`, session
and mbstring INI deprecations, `allow_url_include`, `filter.default`,
`auto_detect_line_endings`, …) or a `Warning:`/`Fatal error:` (`date.timezone`,
invalid mbstring encoding, `session.name`/`upload_progress`, session
save-handler, `zend_test.quantity_value`).
