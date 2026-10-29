# Codebase conventions

Repo-specific idioms: configuration, logging, shared helpers, test patterns,
and build tooling. [CLAUDE.md](../../../../CLAUDE.md) and the files it imports
([general.md](../../../general.md),
[project/index.md](../../../project/index.md)) also apply.

**When planning:** take the rules below into account when designing the
change.

**When reviewing:** report a finding when the diff matches a rule's `Flag:`
line and no `Not when:` applies. Cite the rule ID and name the helper or
pattern to use.

## 1. Configuration

### CONV-1.1: Read config through `configuration.h`, never `getenv`
- Flag: `getenv()` for `DD_*`/`OTEL_*` keys in C, Rust or PHP; OTel keys
  not routed through `configuration.h`/`otel_config.c`; or a PHP `getenv`
  fallback next to `dd_trace_env_config()`.
- ❌ `function_exists('dd_trace_env_config') ? ... : getenv('DD_METRICS_OTEL_ENABLED')`
- ✅ `\dd_trace_env_config('DD_METRICS_OTEL_ENABLED') === true`

## 2. Zend and FFI helpers

### CONV-2.1: Pass strings across FFI as `CharSlice`, built with `dd_zend_string_to_CharSlice`
- Flag: `*const c_char` FFI parameters, hand-built `ddog_CharSlice`
  initialisers, or `Z_PARAM_STRING` whose length is ignored.
- ❌ `(ddog_CharSlice){ .ptr = ZSTR_VAL(s), .len = ZSTR_LEN(s) }`
- ✅ `Z_PARAM_STR(s)` … `dd_zend_string_to_CharSlice(s)`

### CONV-2.2: Put PHP-version API aliases in `compatibility.h`, using the modern name
- Flag: inline `#if PHP_VERSION_ID` blocks at call sites for a renamed API.
- ✅ call `zend_bin2hex` and alias it for older PHP in `ext/compatibility.h`.

### CONV-2.3: Delete from PHP hashtables during iteration directly
- Flag: collecting keys into a temporary array before deleting them;
  `zend_hash_del` is safe inside `ZEND_HASH_FOREACH`.

### CONV-2.4: Shadow `TSRMLS_CACHE` locally to access ZTS globals
- Flag: hand-written TSRM offset lookups to reach a module global from a
  `tsrm_ls` pointer.
- ✅ `void *TSRMLS_CACHE = tsrm_ls; return &DATADOG_G(sidecar);`

### CONV-2.5: Keep PHP lifecycle naming
- Flag: renaming `minit`/`rinit`/`gshutdown`-style functions unless they
  really run outside that lifecycle phase.

### CONV-2.6: Wrap whole functions in platform `#ifdef`s
- Flag: an `#ifdef __linux__` that covers only part of a function body.

## 3. Stubs and arginfo

### CONV-3.1: Regenerate arginfo with php-src master's `gen_stub.php`
- Flag: `*_arginfo.h` changes that look produced by an older generator.

### CONV-3.2: Declare stub constants with `UNKNOWN` and `@cvalue`
- ❌ `const FOO = 3;`
- ✅ `/** @cvalue DDTRACE_FOO */ const FOO = UNKNOWN;`

### CONV-3.3: Put test-only extension functions in `DDTrace\Testing`
- Flag: test helpers in `DDTrace\Internal` (reserved for `src/`), or
  internal helpers wrapped in `DD_TEST_HELPERS` guards (they're kept
  unconditionally).

### CONV-3.4: Add `@return` phpdoc to public `src/api` methods
- Flag: new public getters in `src/api` without `@return`.

## 4. PHP userland

### CONV-4.1: Don't guard extension functions with `function_exists` when the extension is guaranteed
- Flag: `function_exists('DDTrace\...')` on paths only reachable with the
  extension loaded (e.g. already gated by an availability check).
- Not when: the code is reachable through public API without the extension.

### CONV-4.2: Reuse `DDTrace\Log\LoggerInterface` and log swallowed exceptions
- Flag: a bespoke warning/logger abstraction, or a `catch` that drops an
  exception where a logger is available (catch at that call site instead).

### CONV-4.3: Use `spl_object_id` or `install_hook` data, not `spl_object_hash`
- Flag: `spl_object_hash()` (deprecated in PHP 8.6).
- ✅ `install_hook($fn, function (HookData $hook) { $hook->data = ...; })`

### CONV-4.4: Follow PHP-familiar output conventions
- Flag: invented placeholders where PHP already has a convention.
- ✅ `"*RECURSION*"` for cyclic values, as `var_dump` prints.

## 5. Logging

### CONV-5.1: Keep `DD_TRACE_DEBUG` output sparse; put detail at trace level
- Flag: per-request or per-evaluation detail logged at debug level.
- ✅ log it only at `DD_TRACE_LOG_LEVEL=trace`.

## 6. Tests

### CONV-6.1: Run `.phpt` tests that need `src/` through PHPUnit
- Flag: a `tests/ext/` `.phpt` that depends on `src/` code; move it into
  the PHPUnit suite, which can run `.phpt` files.

## 7. Build, CI, and repository files

### CONV-7.1: Generate CI image definitions from docker-compose files
- Flag: image definitions (including Windows) repeated inline in CI YAML
  instead of generated from the compose files.

### CONV-7.2: Don't pin system-tests to a commit
- Flag: a pinned system-tests ref that will need manual bumps.

### CONV-7.3: Don't add per-file license headers
- Flag: license boilerplate in new source files; the repo has a default
  license.

### CONV-7.4: Lint PHP only with `composer ci-lint`
- Flag: adding or referencing a `phpcs.xml` or other lint config.

### CONV-7.5: Regenerate cbindgen headers when components-rs or libdatadog changes
- Flag: changes to any `components-rs/` file or the libdatadog submodule
  without regenerated headers.

### CONV-7.6: Rebuild CI images instead of installing at job time
- Flag: scripts that patch or install service sources into a running job
  as a substitute for an image rebuild.

### CONV-7.7: Keep `needs:` on jobs that consume artifacts
- Flag: removing `needs:` from a job that uses package artifacts because
  it doesn't run on the current branch.
