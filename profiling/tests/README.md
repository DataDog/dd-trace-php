# PHP language test xfail list

The profiler's "PHP language tests" CI job runs the upstream PHP test suite
with the profiling extension loaded, for every supported PHP version and for
both the `nts` and `zts` builds. `php-language-xfail.list` excludes tests that
cannot pass in that environment for reasons unrelated to profiler correctness.

`.gitlab/run_php_language_tests.sh` **deletes** every `.phpt` named in
`XFAIL_LIST` before running, so listing a test means "do not run" it.

Tests fail with the profiler loaded on both NTS and ZTS:

- `ext/ffi/tests/list.phpt` — aborts (`free(): invalid size`); allocation
  profiler conflicts with the test's FFI memory management.
- `Zend/tests/concat_003.phpt` — perf-sensitive (2 s budget); allocation
  profiling overhead can exceed it on CI runners.
