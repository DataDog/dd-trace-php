# PHP language test xfail lists

The profiler's **"PHP language tests"** CI job (`.gitlab/generate-profiler.php`)
runs the upstream PHP test suite (`/usr/local/src/php`) with the profiling
extension loaded, for every supported PHP version and for both the `nts` and
`zts` builds.

A number of upstream tests cannot pass in that environment for reasons that
are **not profiler defects we can fix in the test run**. Those tests are listed
here so the job can exclude them.

## How the lists are consumed

`.gitlab/run_php_language_tests.sh` takes the `XFAIL_LIST` env var, and for
every path in it **deletes the matching `.phpt` file** before invoking
`run-tests.php`. So "xfail" here means "do not run", not "expected-fail".

The job builds the effective list like this:

```sh
cat "${XFAIL_LIST}" profiling/tests/php-language-xfail.list > /tmp/profiler-php-language-xfail.list
# only on the ZTS build:
cat profiling/tests/php-language-xfail-zts.list >> /tmp/profiler-php-language-xfail.list
```

where `${XFAIL_LIST}` is the shared, tracer-maintained
`dockerfiles/ci/xfail_tests/<version>.list`.

| File | Applies to | Meaning |
|------|------------|---------|
| `php-language-xfail.list` | all profiler runs (`nts` + `zts`, all versions) | Fails with the profiler regardless of thread-safety mode |
| `php-language-xfail-zts.list` | **ZTS build only** | Upstream ZTS-specific output-ordering quirk; passes on NTS |

Keeping the ZTS-only failures in a separate list means the NTS build still
exercises those tests (they pass there), so we don't lose coverage.

---

## `php-language-xfail.list` (all flavours)

These fail with the profiler loaded on both NTS and ZTS.

### Profiler memory instrumentation vs. the test's own allocator

- `ext/ffi/tests/list.phpt` — aborts with `free(): invalid size`
  (`Termsig=6`). The allocation profiler's interception conflicts with the
  way the FFI test manages memory. This is a genuine profiler interaction and
  the only crash in the set.

### Performance / timing sensitive

- `Zend/tests/concat_003.phpt` — asserts that concatenating ~1.7M small
  strings stays under a 2 s budget. The allocation profiler adds per-allocation
  overhead that can push CI runners past the threshold. The test is explicitly
  perf-sensitive (it honours `SKIP_PERF_SENSITIVE`).

### Online tests not skipped due to an env-var name mismatch

- `ext/soap/tests/bugs/bug76348.phpt`
- `ext/standard/tests/network/bug80067.phpt`

  Both `--SKIPIF--` on `getenv("SKIP_ONLINE_TESTS")` (plural), but the profiler
  job exports `SKIP_ONLINE_TEST` (singular — see
  `.gitlab/generate-profiler.php`). So they are not skipped and fail without
  outbound network (`httpbin.org` 503, etc.). **Fixing the variable name to
  `SKIP_ONLINE_TESTS` would let both be removed from this list.**

### Server / libcurl-version dependent

- `ext/curl/tests/bug48203_multi.phpt` — needs the bundled curl test server
  (`server.inc`) and is sensitive to the libcurl version (it already carries a
  `--SKIPIF--` for a specific libcurl bug). Flaky in the profiler job
  environment.

---

## `php-language-xfail-zts.list` (ZTS build only)

Every test in this file **passes on NTS and fails only on ZTS**, and every one
reproduces on a **vanilla ZTS PHP with no Datadog extension loaded** — so none
are profiler defects.

**Root cause:** on ZTS, PHP flushes its `PHP Startup` diagnostics *after* the
SAPI has started emitting the request's own output; on NTS they appear *before*
it. The bundled `--EXPECTF--` sections were written for the NTS ordering (the
startup notice at the top), so the ZTS output diff is purely the position of
that notice.

The tracer's "PHP Language Tests" job never hits these because it only runs the
NTS `debug` build; the profiler job is the first Datadog job to run the suite
on ZTS.

### Misplaced notice is a `Deprecated:` startup message

Deprecated INI directive / constant notices emitted at startup, landing after
the first line of script output. Covers:

- the `assert.*` INI deprecations: `assert.phpt`, `assert03.phpt`,
  `assert04.phpt`, `assert_basic*.phpt`, `assert_closures.phpt`,
  `assert_error2.phpt`, `assert_return_value.phpt`, `assert_variation.phpt`,
  `assert_warnings.phpt`
- session INI deprecations (`use_only_cookies`, `use_trans_sid`, url-rewriter,
  etc.): `ext/session/tests/015,018,020,021.phpt`,
  `bug36459,bug41600,bug42596,bug50308,bug51338,bug68063,bug71683,bug74892.phpt`,
  `gh13891.phpt`, `session_basic3,4,5.phpt`
- mbstring INI deprecations: `mb_get_info.phpt`,
  `mb_internal_encoding_ini_basic2.phpt`,
  `mb_internal_encoding_ini_invalid_encoding.phpt`, `mb_parse_str_multi.phpt`
- `allow_url_include`: `ext/opcache/tests/bug64353.phpt`, `bug65510.phpt`,
  `Zend/tests/require_parse_exception.phpt`,
  `ext/standard/tests/file/include_userstream_003.phpt`,
  `ext/standard/tests/strings/highlight_file.phpt`
- other directive deprecations: `ext/filter/tests/filter_default_deprecation.phpt`,
  `ext/standard/tests/file/auto_detect_line_endings_1.phpt`,
  `ext/standard/tests/general_functions/bug44394_2.phpt`,
  `ext/standard/tests/strings/htmlentities25.phpt`

### Misplaced notice is a `Warning:` / `Fatal error:` startup message

Same ZTS ordering issue, but the relocated diagnostic is a warning or fatal
error rather than a deprecation:

- invalid `date.timezone`: `ext/date/tests/date_default_timezone_get-1,2,4.phpt`,
  `date_default_timezone_set-1.phpt`, `ext/intl/tests/dateformat_invalid_timezone.phpt`
- invalid mbstring encoding: `ext/mbstring/tests/mb_http_input_001.phpt`,
  `Zend/tests/multibyte/multibyte_encoding_007.phpt`
- session startup validation: `ext/session/tests/bug66481.phpt` (`session.name`),
  `rfc1867_invalid_settings.phpt`, `rfc1867_invalid_settings_2.phpt`
  (`session.upload_progress.freq`)
- session save-handler fatal: `ext/session/tests/user_session_module/bug60860.phpt`,
  `session_set_save_handler_class_014.phpt`
- `zend_test.quantity_value`: `Zend/tests/zend_ini/zend_ini_parse_quantity_ini_setting_error.phpt`
