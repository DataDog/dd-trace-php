# PHP2Shell mitigation in AppSec: reference for maintainers and support

This document describes how the Datadog AppSec component of the PHP tracer
(built into `ddtrace.so`; sources in the `appsec/` directory of the dd-trace-php
repository) protects unpatched PHP runtimes against the two "PHP2Shell"
vulnerabilities, CVE-2026-109618 and CVE-2026-109619. It is written for
maintainers and support engineers who need to understand what the mitigation
does, what it promises, and where its known limits are, without having followed
its design.

## 1. Introduction

### 1.1 The vulnerabilities

PHP enforces `memory_limit` inside its memory allocator (zend_mm). When an
allocation would take the request's memory usage above the limit, the
allocator raises the fatal error "Allowed memory size of N bytes exhausted"
and immediately unwinds the request with a long jump (a *bailout*). The
code that asked for memory never gets control back.

- **CVE-2026-109618.** On PHP 7.3 and later, the function that inserts a
  string key into a hash table (`_zend_hash_str_add_or_update_i()`) counts
  the new entry before it allocates the key. If that allocation crosses
  `memory_limit`, the bailout leaves a counted but uninitialised entry in
  the table, and request shutdown later reads it as if it were a valid
  value. PHP parses the request's query string, cookies and body into
  `$_GET`, `$_COOKIE` and `$_POST` before any application code runs, so a
  crafted request alone can trigger this and corrupt memory.
- **CVE-2026-109619.** After a bailout, some engine state that belongs to
  the request (for example pointers to the user error handler, the object
  store and the last error) can be left pointing into the request's memory
  after that memory was freed. In addition, session upload progress
  processes attacker-controlled data before the extensions' request
  initialisation runs. Both can be abused once a bailout happened.

### 1.2 Affected and fixed PHP versions

Upstream PHP fixed both vulnerabilities together in **8.2.35, 8.3.36,
8.4.27 and 8.5.12**. Earlier releases of those branches are affected. The
fix changes the allocator: an allocation that crosses `memory_limit` is
allowed to succeed, the out-of-memory error is raised later at a point
where it cannot interrupt an insertion, and a second, higher *hard limit*
ends the process with exit status 137 if memory keeps growing.

The end-of-life branches (7.0 to 8.1) received only a smaller fix, which
some Linux distributions backport: on those builds a `memory_limit` error
ends the process (exit status 1) instead of unwinding the request. The
mitigation treats such builds as still unpatched. Distributions also
backport the full fix to versions below the upstream fixed releases (for
example Ubuntu's 8.3.6 and 8.5.4), so the mitigation never relies on the
version number: it inspects the PHP binary. PHP 8.6 is counted as patched,
because its first stable release contains the fix.

### 1.3 What the mitigation does

On an unpatched runtime, AppSec reproduces the upstream fix from inside
an extension:

- It **arms** the allocator during the phases where the vulnerable
  insertions can happen: the parse of the request data before the request
  starts, the `request_parse_body()` function (PHP 8.4+), and, on PHP 7.1
  and later, the whole request from AppSec's request initialisation to its
  request shutdown. While armed, AppSec's own allocator handlers enforce
  the limits instead of zend_mm.
- When an allocation crosses `memory_limit`, it lets the allocation
  succeed and **defers the error** to a *safe point*: the end of the parse,
  or the next point where the PHP virtual machine checks for interrupts
  (between two opcodes, the same mechanism `max_execution_time` uses).
  There it raises the usual fatal error, with a suffix that identifies the
  mitigation.
- If memory keeps growing past a **hard limit** before a safe point is
  reached, the process ends immediately with exit status 137.
- It resets the stale engine state after every request and turns session
  upload progress off while it parses request bodies (the two
  sub-mitigations for CVE-2026-109619).
- It reports deferred errors and hard exits through metrics, span tags and
  log lines.

### 1.4 How it is activated

The setting `DD_APPSEC_PHP2SHELL_MITIGATION` (INI
`datadog.appsec.php2shell_mitigation`) selects the *mode*:

- `auto` (the default): active only on an unpatched runtime. Whether the
  allocator is armed is decided by the AppSec WAF ruleset, per thread.
- `on`: armed on any runtime, including patched ones, without asking the
  WAF. On a patched runtime the CVE-2026-109619 sub-mitigations still do not
  run, because the engine already does that work.
- `off`: nothing is installed.

The mitigation also requires that AppSec is enabled, that the tracer is not
explicitly disabled (`DD_TRACE_ENABLED`) and that ddtrace is not
hard-disabled (`ddtrace.disable`, which ddtrace also sets itself under a
SAPI it does not support, such as LiteSpeed or apache2filter), and it is off
under the `cli`, `phpdbg`, `embed` and `micro` SAPIs. Every `ddtrace.so`
built with AppSec (all Linux installer packages) contains the mitigation;
builds without `--enable-ddtrace-appsec` do not, and neither do the Windows
packages, which have no AppSec.

Under the mode `auto`, AppSec tells the AppSec helper, at the start of
every request, whether the runtime is fully unpatched (`full`) or carries
only the end-of-life fix (`partial`). The helper evaluates the rule
`dd-php2shell-001` against that value and returns a *WAF decision*:

- `block`: the mitigation is armed.
- `monitor`: the mitigation is not armed; instead, AppSec watches for the
  engine's own memory errors and reports them on the request's span (the
  *monitor state*). Monitor mode detects attacks; it does not prevent them.
- `none`: the mitigation is not armed and nothing is reported.

The default ruleset shipped with AppSec (`appsec/recommended.json`)
answers `block`.

### 1.5 How to read this document

Section 2 defines the terms used throughout. Section 3 lists the PHP
version differences that change the mitigation's behaviour. Section 4 states
the core guarantee and the global scope. Sections 5 to 12 each cover one
area and contain:

- a short description of how it works;
- **Guarantees**, numbered G1, G2, ... across the whole document. Each is a
  statement that can be tested;
- **Exceptions and limitations**: known behaviours that are intentional.
  Where one qualifies a guarantee it starts with "Exception to G*n*". The
  bold names match the labels of the coverage list at the top of
  `appsec/src/extension/php2shell_mitigation.c`, which is the authoritative
  compatibility list;
- **Out of scope**, where relevant: situations the mitigation does not try
  to handle at all.

Section 13 explains how to decide whether a report is a bug. Sections 14
and 15 list known issues in other software and known open issues.

## 2. Glossary

- **PHP2Shell**: the two vulnerabilities CVE-2026-109618 and
  CVE-2026-109619 (section 1.1).
- **Bailout**: the long jump with which the PHP engine abandons the
  current execution after a fatal error (`zend_bailout()`).
- **Native bailout**: the engine's own bailout from inside an allocation
  that crossed `memory_limit`; this is what triggers CVE-2026-109618.
- **Patched runtime**: a PHP binary that exports `_femalloc` (an
  allocation function added by the full upstream fix), or PHP 8.6 and later.
- **Full runtime**: an unpatched binary with neither the full fix nor the
  end-of-life fix.
- **Partial runtime**: an unpatched binary that carries only the
  end-of-life fix (it exports `zend_mm_safe_error_ex` but not `_femalloc`),
  as backported to PHP 7.0-8.1. A `memory_limit` error there ends the
  process with exit status 1. It counts as unpatched for everything the
  mitigation does.
- **Mode**: the value of `DD_APPSEC_PHP2SHELL_MITIGATION` /
  `datadog.appsec.php2shell_mitigation`: `auto`, `on` or `off`
  (section 1.4). Accepted spellings are case-insensitive: `on`, `1`,
  `true`, `yes`; `off`, `0`, `false`, `no`; an empty value means `off`.
  Any other value means `auto` and logs the warning "unknown mode '...',
  defaulting to 'auto'".
- **WAF decision**: the answer of the rule `dd-php2shell-001`, kept per
  thread: `pending` (the initial value, which arms like `block`), `none`,
  `monitor` or `block`. It changes only when a new answer arrives.
- **Active**: the mitigation's hooks are installed, the mode allows this
  runtime, and AppSec is enabled for the current thread. Activity controls
  the stale-state cleanup, the checks for replaced hooks and the phpinfo
  rows.
- **Armed** (policy): active, and either the mode is `on` or the WAF
  decision is `pending` or `block`. This decides whether the protected
  phases arm the allocator.
- **Armed** (allocator): AppSec's allocator handlers are enforcing the
  soft and hard limits, and the engine's own limit is set to the sentinel.
- **Monitor state**: mode `auto` with WAF decision `monitor`. Nothing is
  armed; native memory errors are detected and reported.
- **Soft limit (S)**: `memory_limit`, but at least one zend_mm chunk
  (2 MiB). With a negative `memory_limit` such as -1 ("no limit") the soft
  limit is the largest possible value. A `memory_limit` of 0 is not "no
  limit": PHP 7.x and 8.0 accept it and enforce one chunk, so the soft
  limit is 2 MiB (8.1+ refuse it and keep the previous value). Crossing
  the soft limit makes an error pending.
- **Hard limit (H)**: the limit above which the process ends with exit
  status 137.
- **Upstream floor (L)**: the hard limit of the upstream fix: twice the
  soft limit, or the soft limit plus 1 GiB once the soft limit reaches
  1 GiB. AppSec's hard limits are never below it.
- **Margin (M)**: 8 MiB (`DD_PHP2SHELL_HARD_MARGIN`), a fixed allowance
  added to several limits.
- **Input bound (B)**: the size of the input a window parses: for POST,
  `post_max_size` (or the number of body bytes already read, if larger);
  for GET, the query string length; for COOKIE, the cookie header length;
  for `request_parse_body()`, its `post_max_size` option or the INI value.
- **Growth factor (k)**: how many times B the parse is allowed to grow
  above the soft limit: 6, or 11 for a multipart body.
- **Window cap**: a constant ceiling on a window's growth above the soft
  limit: the larger of 1 GiB and 2S (4S for a multipart body).
- **Sentinel**: the value `SIZE_MAX - 1`, written into the engine's limit
  while armed, so zend_mm itself never enforces `memory_limit`. A runtime
  with the full upstream fix caps the engine's limit at `PHP_INT_MAX`
  (also what `memory_limit=-1` writes), so with the mitigation forced on
  there the sentinel is `PHP_INT_MAX - 1`.
- **Window**: one armed parse of request data, with its own limits, whose
  pending error is raised at its end. The **startup window** is the parse
  of POST, GET and COOKIE data before the extensions' request
  initialisation; the **`request_parse_body()` window** is a call to that
  function (PHP 8.4+).
- **Nested window**: a `request_parse_body()` window that starts while the
  request level is armed. It only switches to window limits for its
  duration.
- **Request level**: on PHP 7.1 and later, arming from early in AppSec's
  RINIT (request initialisation) to the start of its RSHUTDOWN (request
  shutdown).
- **Crossing**: an allocation that leaves memory usage above the soft limit
  even after a garbage-collection attempt.
- **Pending**: a crossing has been recorded and its error not yet raised.
- **Safe point**: a place where raising the error cannot abandon an
  insertion: the end of a window's parse, or a VM interrupt (between two
  opcodes).
- **Deferred fatal** (or **raise**): the error AppSec raises at a safe
  point: E_ERROR "Allowed memory size of N bytes exhausted (Datadog AppSec
  PHP2Shell mitigation deferred this to avoid heap corruption)", followed
  by a bailout. N is the soft limit that was crossed (the **breached soft
  limit**).
- **Raise refused**: the VM interrupt arrived while the tracer sandbox was
  running, so the deferred fatal was not raised and stays pending.
- **Tracer sandbox**: the protection ddtrace puts around the hook closures
  it runs. It switches PHP to exception-throwing error handling
  (`EH_THROW`) and swallows bailouts.
- **Hard exit**: ending the process with `_exit(137)` at the hard limit.
- **Floor F, cap C**: the request-level limits after a deferred fatal at the
  request level (section 7). F is a raised soft limit; C is the hard limit.
- **Finite limit**: `max(memory_limit, usage + 8 MiB)`, written into the
  engine instead of a limit that current usage no longer fits, or instead
  of the sentinel, whenever AppSec stops enforcing.
- **Installed**: AppSec's allocator handlers are in the heap's chain of
  custom handlers, either on top or under another extension's.
- **Captured / replaced**: a hook slot whose previous value AppSec saved
  at startup is *captured*. It is *replaced* when AppSec's function is no
  longer the one on top of the slot. Replaced does not imply that
  AppSec's function is no longer called: the newcomer may chain to it.
- **No-user-code rule**: no application code runs while a window is armed.
- **Sub-mitigation B**: the reset of stale request state after each
  request (section 9).
- **Sub-mitigation C**: turning session upload progress off while request
  bodies are parsed (section 9).
- **Drop**: after a crossing in a window, later request variables are
  discarded instead of registered.
- **FILE_START skip**: after a crossing, later file parts of a multipart
  body are skipped.
- **End of body**: after a crossing in a multipart parse, the body reader
  reports that the body has ended.
- **Generation**: a counter increased each time the module starts. A
  thread that survives a module restart (for example an Apache graceful
  restart) starts again from the initial per-thread state.

## 3. PHP version facts

The mitigation's behaviour depends on the exact PHP version, because the
engine's memory-limit code changed in several patch releases.

Two ways of setting the limit changed in different releases:

- `ini_set('memory_limit', ...)` goes through the INI handler. From 7.4.21
  and 8.0.8 it refuses a limit below the current usage ("Failed to set
  memory limit ..."). Before those releases it accepts it (see the
  exception **memory_limit lowered below usage** in section 7; it concerns
  7.1-7.3, 7.4.0-7.4.20 and 8.0.0-8.0.7, as 7.0 has no request level).
- The C function `zend_set_memory_limit()`, which PHP calls before every
  fatal error, does not go through the INI handler. It accepts a limit
  below usage before 7.4.21 and on all of 8.0, and refuses it from 7.4.21
  and on 8.1+. When it accepts one, the allocator's limit check wraps
  around on 7.2+ (no effective limit) and fails at once on 7.0/7.1. This is
  why every fatal error AppSec raises while usage may be above
  `memory_limit` first sets a finite limit.

| Fact | Versions |
|---|---|
| Request level (needs VM interrupts) | 7.1+ (7.0: windows only) |
| Vulnerable count-before-allocate insertion (CVE-2026-109618) | 7.3+ (7.0-7.2 allocate the key first) |
| `request_parse_body()` and its window | 8.4+ |
| `gc_mem_caches()` wrapper needed (zend_mm frees nothing with custom handlers) | 7.1-8.3 |
| Setting the limit frees cached chunks without writing the limit (a second call is needed) | 8.1.4+ |
| "Soft limit below usage" exception applies | 8.1.4+ |
| The INI handler writes the engine limit unconditionally | 7.1-7.3, 7.4.0-7.4.20 |
| Sentinel writable from PHP as -2 / as 18446744073709551614 | 7.0-8.1 / 8.2+ |
| Engine limit capped at `PHP_INT_MAX` (sentinel `PHP_INT_MAX - 1`, writable as 9223372036854775806) | full upstream fix |
| Partial runtimes (end-of-life fix, `zend_mm_safe_error_ex`) | builds of 7.0-8.1 |
| Full upstream fix (`_femalloc`) | 8.2.35, 8.3.36, 8.4.27, 8.5.12; 8.6 counted as patched |

## 4. Scope and core guarantee

The mitigation pursues five goals, stated in the header of
`php2shell_mitigation.c`:

- **Deferral**: on an unpatched runtime, a `memory_limit` error during the
  startup parse of request variables or during `request_parse_body()` never
  interrupts an insertion; it is deferred to a safe point and bounded by the
  hard limit. On PHP 7.1+ the same holds for insertions at runtime (for
  example `parse_str()` and internal array builders), deferred to the next
  VM interrupt.
- **Stale state**: the CVE-2026-109619 state is reset after each request,
  and session upload progress is off during the startup POST parse.
- **Gating**: the mitigation follows the patch state, the mode, AppSec's
  state and the SAPI; under the mode `auto` it is armed only as the WAF
  decides.
- **Reporting**: two metrics, span tags and trace retention (section 11).
- **No false hard exits**: bodies within `post_max_size` do not end in a
  hard exit (section 8).

Recycling workers is not a goal: a worker process ends only where the
engine itself would end it.

### Guarantees

- **G1.** On an unpatched runtime where the mitigation is armed, a
  crossing during a window, or on PHP 7.1+ at the request level, never ends
  in a native bailout in the middle of an insertion. Either the error is
  raised as the deferred fatal at a safe point, or the process ends with a
  hard exit.

### Exceptions and limitations

- **Outside RINIT..RSHUTDOWN.** Exception to G1: the request level covers
  AppSec's part of the ddtrace RINIT (including its exchange with the helper) up
  to the start of AppSec's part of RSHUTDOWN. The tracer's and the Datadog
  profiler's parts of the RINIT, which run before AppSec's, the RINIT of
  extensions loaded before ddtrace.so, RSHUTDOWNs that run after AppSec's (the
  profiler's and the tracer's, and notably the session write, as the built-in
  session module shuts down after ddtrace), and output handlers flushed after
  AppSec's RSHUTDOWN run unarmed. A block or a fatal error in AppSec's RINIT
  ends the request level at once, so AppSec's own request shutdown and the
  RSHUTDOWNs the block runs are unarmed too. On PHP 7.3 with opcache loaded,
  every extension's RINIT runs before AppSec's and every RSHUTDOWN after it
  (see **Module order on PHP 7.3 with opcache**, section 6).
- **Handlers dropped from the chain.** Exception to G1: if, at the end of a
  request, another extension's allocator handlers are on top of
  AppSec's, AppSec assumes its own stay chained underneath. If that
  extension later removes itself without putting AppSec's back while a
  third extension's handlers occupy the slot, AppSec's handlers are no
  longer called, yet arming still sets the engine's limit to the sentinel.
  Those requests run with no memory limit at all, until the slot shows
  AppSec's handlers or plain zend_mm again.
- **Displaced hooks** (startup-window hooks). Exception to G1: if another
  extension replaces, after startup, one of the hooks the startup window
  relies on (`sapi_module.treat_data` and the others listed in section 10),
  that window is not armed. For `input_filter`, see **Displaced
  input_filter** in section 8.
- Other exceptions to G1 that belong to one mechanism are listed in its
  section and start with "Exception to G1".

### Out of scope

The following are not protected at all; the vulnerabilities stay reachable
there:

- **Monitor mode**, the decision `none`, the mode `off`, and patched
  runtimes under the mode `auto` (which have the upstream fix).
- **CLI SAPIs**: `cli`, `phpdbg`, `embed` and `micro` (phpmicro), in every
  mode, as if AppSec were disabled. RoadRunner and Ratchet run under the CLI
  SAPI and are therefore not covered; PHP does not parse their HTTP requests
  anyway. `cli-server` (`php -S`) and FrankenPHP are covered.
- **SAPIs ddtrace does not support**: under a SAPI that ddtrace does not
  recognise as compatible (LiteSpeed, apache2filter or any unknown SAPI),
  ddtrace disables itself as with `ddtrace.disable=1`, so AppSec is fully
  disabled and no hook is installed.
- **Failed Datadog startup**: if the tracer's or the profiler's
  zend_extension startup fails, ddtrace's startup stops before AppSec
  captures its hooks, so none is installed and the process runs unprotected
  (fail open).
- **USE_ZEND_ALLOC=0**: nothing is armed. The plain system allocator
  enforces no limit; with `USE_TRACKED_ALLOC=1` (PHP 8.0+, test-only) a
  crossing bails out as on an unprotected runtime.
- **Incompatible extensions**: with memprof or Snuffleupagus loaded, no
  hook is installed, a warning "PHP2Shell mitigation: off, the ... extension
  is loaded" is logged and phpinfo shows "PHP2Shell incompatible extension".
  memprof swaps in its own heap, and a client can turn it on per request;
  Snuffleupagus re-wraps AppSec's upload hook (endless recursion) and its
  INI protection turns the upload-progress toggle into a bailout.
- **PHP 7.0, after RINIT**: PHP 7.0 has no VM interrupt mechanism usable
  for the request level, so only the startup parse is armed. A later
  crossing gets the engine's own error. The CVE-2026-109618 insertion bug
  does not exist on 7.0-7.2, which allocate the key before counting the
  entry.
- **OS allocation failures**: under `RLIMIT_AS` or strict memory overcommit,
  an allocation (including a reallocation that moves the block) can be
  refused by the operating system, and the engine's own "Out of memory"
  bailout follows.
- **Unrelated stock-PHP bugs** that are not about memory (for example the
  `parse_str` user-handler use-after-free).
- **Exotic extensions**: compatibility beyond chaining what is captured at
  startup (see section 10).
- **Worker recycling**: apache2handler keeps the process after a deferred
  fatal, unless an extension ends it; php-fpm and php-cgi exit after a
  deferred fatal from the startup parse, as they would after the engine's
  own error there; a deferred fatal from `request_parse_body()` ends only
  the request.

## 5. Activation

When the module starts, AppSec determines the patch state from the
binary (patched, partial or full). At the end of startup, after every
module has initialised, it decides whether to install its hooks at all,
based on the configuration visible at that moment (under php-fpm, the
master's). It installs nothing when the SAPI is excluded, when the mode is
`off`, when the mode is `auto` on a patched runtime, when AppSec is
explicitly disabled, when the tracer is explicitly disabled or ddtrace is
hard-disabled (`ddtrace.disable=1`, also set by ddtrace under a SAPI it does
not support), or when an incompatible extension is loaded. Otherwise it
installs its hooks, because remote configuration may enable AppSec later.
This decision is the last step of ddtrace's zend_extension startup, so it is
skipped, and nothing is installed, when the tracer's or the profiler's
startup before it fails.

The mode itself is resolved once per process, at the first request, after
the per-worker configuration refresh (done by ddtrace's activation hook,
before the request data is parsed), so php-fpm pool `env[]` values are
taken into account.

Two values are kept per thread: whether AppSec allows the mitigation (set
from configuration, cleared at RINIT when AppSec is fully disabled, and
updated by remote configuration) and the WAF decision. Under the mode
`auto`, every request initialisation message to the helper carries the
address `php.php2shell.vulnerable` with the value `full` or `partial`. The
helper evaluates rule `dd-php2shell-001` on its own, separately from the
request's normal WAF run and without a timeout, caches the answer per
connection and ruleset, and returns it in the response's settings under
the key `php2shell_mitigation`. A rule action with mode `block` or
`monitor` maps to that decision; no match, or an action with a missing or
unknown mode, means `none` (the latter is logged once per connection and
ruleset). A WAF error gives no decision. When no answer arrives (AppSec not
active, the helper unreachable, an error), the previous decision is kept.

The decision obtained in a request applies from the end of that request's
RINIT. In FrankenPHP worker mode, where one PHP request spans many HTTP
requests, it is applied again at the start of each HTTP request.

### Guarantees

- **G2.** Under the mode `auto`, the allocator is armed only when the
  runtime is unpatched, the SAPI is covered, no incompatible extension is
  loaded, AppSec is enabled for the request, and the WAF decision is
  `pending` or `block`. Under the mode `on`, the same holds without the
  patch and WAF conditions.
- **G3.** When AppSec is disabled for the thread, nothing is armed,
  whatever the WAF decision; the mode `on` does not override AppSec's
  state.
- **G4.** A patched runtime never sends `php.php2shell.vulnerable`. The
  modes `on` and `off` never send it, and the mode `on` never consults the
  WAF. The mode `off` and the excluded SAPIs install no hook and write no
  hook slot.
- **G5.** A response without the `php2shell_mitigation` setting leaves the
  decision unchanged. An unknown value of that setting is logged and
  ignored.
- **G6.** The helper never returns a cached decision from an earlier
  ruleset after the ruleset changes, and a cached answer is always what a
  fresh evaluation would return.
- **G7.** A match of the PHP2Shell rule never produces an AppSec security
  event on the request, a record action, a trace keep or a WAF metric, and
  its evaluation is never cut short by the WAF timeout.
- **G8.** A thread's decision changes only at that thread's own request
  initialisation; within one HTTP request, all armed phases after the
  decision arrives follow the same decision.
- **G9.** The per-thread state is initialised once per thread and module
  load, before it is first used, and this initialisation never overwrites a
  value already set by RINIT, remote configuration or the testing setter.
  After a module restart that keeps the thread (for example an Apache
  graceful restart), the thread starts again with the decision `pending`.

### Exceptions and limitations

- **Startup gating.** Exception to G2: when the configuration seen at
  startup rules the mitigation out, no hook is installed, so a php-fpm pool
  cannot re-enable what the master's configuration disabled.
- **opcache.preload.** Exception to G2: under php-fpm with
  `opcache.preload`, the preload request runs in the master, which resolves
  the mode and AppSec's state there, so the workers do not see pool `env[]`
  values. AppSec's own configuration behaves the same way.
- **WAF decision.** Exception to G2: the decision reaches a thread from a
  request's initialisation, so that request's startup parse and its RINIT
  up to the helper's reply follow the previous decision. Each new process
  or thread (each php-fpm worker, each ZTS thread) arms its first startup
  parse whatever the ruleset says, and threads converge separately. Under
  remote configuration, a change of AppSec's state reaches the thread the
  same way.
- **Per-directory datadog.appsec.enabled.** Exception to G2 and G3: this
  setting follows AppSec's own once-per-process resolution. A value set for
  the first request reaches the thread (from that request's RINIT, after
  its startup parse); turning it off in a later request does not close the
  gate for that request.
- A remote-configuration ruleset that does not contain rule
  `dd-php2shell-001` answers `none` and disarms the mitigation. The
  rulesets used by the AppSec integration and phpt tests lack the rule, so
  under the mode `auto` those tests run disarmed after their first request.
- Errors of the separate PHP2Shell evaluation are logged by the helper but
  are not counted in the WAF error telemetry.
- phpinfo and the testing status functions show the decision and AppSec
  flag of the thread that serves the request, not a process-wide value.

## 6. Protected phases and request lifecycle

AppSec captures its hook slots once, at the end of startup, after the
profiler's zend_extension startup, and restores them at module shutdown
(section 10). Its allocator handlers are different:
they are installed when arming starts and removed when it ends. For one
request:

1. **Extension activation** (ddtrace's zend_extension activate hook, before
   the tracer's activation). If the previous request skipped its shutdown
   (`php -S` before PHP 8.1, NGINX Unit after a failed request startup) and
   left the allocator armed, the leftover state is cleared and a finite
   limit is set. At the first activation of a forked process, AppSec binds
   it to its pool's startup span slot (section 11). Nothing is armed here.
2. **Startup window.** When PHP parses POST, GET and COOKIE data before the
   extensions' request initialisation, and the policy is armed, AppSec
   turns session upload progress off (for POST, on unpatched runtimes),
   hides the user error handler, sets `display_errors=0`, installs its
   handlers and arms them (only with a finite `memory_limit`, that is,
   not negative, and the zend_mm allocator). At the end of the parse it
   raises the pending error, if any (first saving the data for the
   request's span in the startup span slot, section 11), or disarms,
   restores `memory_limit` and removes its handlers.
3. **AppSec RINIT** (the last part of the ddtrace RINIT, after the tracer
   and the profiler). If the startup span slot of the process's pool holds
   an event and tracing and root span generation are on for this request,
   AppSec takes it and sends its span (section 11), whatever AppSec's own
   state. If AppSec is fully disabled, the thread's flag is cleared and
   nothing else happens. Otherwise, on PHP 7.1+, the request level is armed
   with the previous decision (or `pending` on a new thread), also when
   `memory_limit` is negative so that a later `ini_set()` is followed. Then the
   helper exchange runs. At the end of RINIT the request level is
   reconciled with the decision that exchange returned.
4. **Reconciliation.** If the request should be armed, the request level is
   opened or re-armed (resetting any limits left from an earlier deferred
   fatal). If it should not, the request level is disarmed and
   `memory_limit` restored, unless an error is pending: then it stays armed
   until the error is raised. The handlers stay installed until RSHUTDOWN.
5. **FrankenPHP worker mode.** At the start of each HTTP request, after its
   helper exchange, the request level is reconciled again.
6. **`request_parse_body()` window** (PHP 8.4+). When the request level is
   armed, the window is nested: it only switches to window limits and
   returns to the request-level limits at its end. Otherwise it arms like
   the startup window.
7. **AppSec RSHUTDOWN** (the first part of the ddtrace RSHUTDOWN), first
   thing: disarm (a still-pending error is
   dropped), restore `memory_limit` or set a finite limit, and remove the
   handlers if they are on top of the slot. If another extension's handlers
   are on top, the slot is left alone and a warning "memory manager handlers
   were replaced" is logged once.
8. **post_deactivate** (first in ddtrace's post_deactivate). Any leftover arming
   is cleared. If RSHUTDOWN was skipped and AppSec's handlers are on top, the
   heap is reset to plain zend_mm. Then, if active, the replaced-hook checks and
   sub-mitigation B run.
9. **SAPI deactivation.** AppSec's `sapi_module.deactivate` wrapper resets
   the object store and clears the last error (section 9).
10. **Module shutdown** (first in ddtrace's MSHUTDOWN, before the
    profiler's and the tracer's). If AppSec's handlers are still on the heap
    (php-fpm and php-cgi skip request shutdown after a failed request
    startup), the heap is reset to plain zend_mm before anything else; then
    every hook slot that still holds an AppSec wrapper is restored.

### Guarantees

- **G10.** No state from a previous request survives into the next one:
  after extension activation nothing is armed, no error is pending, no
  limits from an earlier deferred fatal remain, and the engine's limit is
  finite. A pending error is never carried into the next request.
- **G11.** A startup window hands nothing over to the rest of the request:
  it ends either by raising the deferred fatal or with everything disarmed
  and its handlers removed.
- **G12.** A nested `request_parse_body()` window never installs handlers
  again and never disarms the request level; after it, the request-level
  limits are in force again.
- **G13.** On PHP 7.1+, the request level is armed whenever the policy is
  armed; there is no separate setting for it. On PHP 7.0 it is never armed.
- **G14.** When a request is blocked, or a fatal error happens, in the armed
  part of AppSec's RINIT, AppSec's allocator handlers are removed before
  RINIT is left.
- **G15.** At most one copy of AppSec's allocator handlers is ever in the
  heap's chain.
- **G16.** AppSec's module shutdown resets the heap to plain zend_mm, as its
  first step and before the profiler's and the tracer's module shutdown,
  whenever AppSec's handlers are still installed, so the engine's final
  memory-manager shutdown never calls into AppSec's handlers
  (`php2shell_mitigation_lifecycle_fallbacks.phpt` exercises this).
- **G17.** In FrankenPHP worker mode, disarming the request level for an
  HTTP request whose decision is not to arm never drops a pending error.
- **G18.** In FrankenPHP worker mode, a deferred fatal in one HTTP request
  never loosens the limits of the next.

### Exceptions and limitations

- **WAF decision** (worker mode). Exception to G8: in FrankenPHP worker
  mode, the superglobals FrankenPHP re-imports for an HTTP request before
  its helper exchange, and the worker script's code between HTTP requests,
  follow the previous HTTP request's decision. A pending error is still
  raised, at the next VM interrupt outside the tracer sandbox.
- Exception to G11: startup-window handlers that are still chained under
  another extension's handlers at the end of the window are armed and left
  in place rather than removed; the "memory manager handlers were replaced"
  warning is logged once per process. On PHP 7.0, AppSec may likewise keep
  considering its handlers installed after such a window.
- **NGINX Unit.** After a failed request startup (for example a deferred
  fatal from the startup parse), NGINX Unit skips request shutdown, as it
  does with stock PHP. The window was already disarmed under a finite limit;
  a request left armed (because a later module's RINIT failed) is cleared
  when the next request starts. `php -S` before PHP 8.1 behaves the same.
- **Module order on PHP 7.3 with opcache.** Modules run their RINIT in the
  order of the module registry and their RSHUTDOWN in reverse, so an
  extension loaded after ddtrace.so normally runs its RINIT after AppSec's
  (armed) and its RSHUTDOWN before AppSec's. On PHP 7.3, opcache frees the
  shared memory that holds INI values in its module shutdown, so ddtrace
  swaps its module with opcache's, which opcache registers last, to shut
  down before it. With opcache loaded as a zend_extension (enabled or not),
  ddtrace is then last in the registry: every extension's RINIT runs before
  AppSec's, unarmed, so a crossing there gets the engine's own error, as for
  an extension loaded before ddtrace.so; and every extension's RSHUTDOWN
  runs after AppSec's, unarmed, so **Skipped RSHUTDOWN** (section 7) cannot
  happen. The order of the tracer, the profiler and AppSec inside ddtrace.so
  is unchanged.

## 7. Limits, deferred errors and hard exit

While armed, the engine's limit is the sentinel and AppSec's allocator
handlers forward every allocation to the handlers that were in place before
(or to zend_mm). Before forwarding, they put the sentinel back if PHP wrote
another limit (PHP does so before every fatal error and on `ini_set()`); a
changed `memory_limit` outside a window becomes the new soft limit. A new,
very large allocation that would cross the hard limit ends the process
before reaching the operating system. After each allocation, usage is
checked: above the hard limit the process exits with status 137; above the
soft limit, AppSec first tries a garbage collection of the allocator's
caches and, if usage is still above, records the error as pending and,
outside a window, requests a VM interrupt.

**Limits.**

- Window: `H = min(max(L, S + k*B + M), S + window cap)`, computed once when
  the window arms. With default settings the upstream floor 2S dominates.
  A `post_max_size` of 0 or less makes B unbounded, so H = S + window cap.
  All arithmetic saturates at the largest representable value.
- Request level: `H = max(L, S + 8 MiB)`; no limit when `memory_limit` is
  negative.
- Nested window: the larger of the window's and the request level's limits.
- After a deferred fatal at the request level, with U the usage at that
  moment: the soft limit becomes `max(memory_limit, F)` with
  `F = max(breached soft limit, U + 8 MiB)` (raised by later deferred
  fatals, but never above `C - 8 MiB`), and the hard limit becomes
  `C = max(request hard limit, F + 8 MiB)`. C moves only when
  `memory_limit` changes.

**Raising.** At the end of a window, or at a VM interrupt, AppSec first
calls the interrupt handlers of other extensions, then raises the pending
error if it is outside a window and not inside the tracer sandbox. Inside
the sandbox it leaves the error pending ("raise refused") and asks for
another interrupt on every later allocation. An interrupt that arrives while
the other handlers run (a timer, a second signal) raises at once.

At the request level, a deferred fatal leaves the request armed until
AppSec's RSHUTDOWN, under F and C. A deferred fatal from a startup window,
or from a `request_parse_body()` window the request level did not arm,
disarms first and sets a finite limit. Every fatal error that AppSec
raises while unarmed and possibly above `memory_limit` (window raises, its
own out-of-memory and block errors, body-parser errors) goes through a
helper that sets a finite limit first.

A deferred fatal from a startup window fails the request startup, so no
module RINIT or RSHUTDOWN runs for that request (php-fpm and php-cgi then
exit). Under the finite limit, before raising, AppSec builds `$_SERVER` and
copies what the request's span needs into its pool's startup span slot;
nothing of the tracer runs then. A later request sends the span (section 11).

**Hard exit.** AppSec stops enforcing, records the
`appsec.php2shell.hard_limit_exit` metric, flushes it synchronously, writes
one line to standard error ("[ddappsec] PHP2Shell mitigation: hard memory
limit of N bytes exhausted (tried to allocate N bytes); terminating to
avoid heap corruption") and calls `_exit(137)`.

### Guarantees

- **G19.** While armed, the engine never raises its own `memory_limit`
  error: any "Allowed memory size" fatal carries the Datadog suffix.
- **G20.** The limit arithmetic saturates: very large `memory_limit` or
  `post_max_size` values never wrap a limit around to a small value, and no
  computed limit reaches the sentinel.
- **G21.** A window's hard limit is never below the upstream floor L, and a
  larger input bound never lowers it.
- **G22.** After a deferred fatal at the request level, the request stays
  armed until AppSec's RSHUTDOWN; the hard limit stays at least 8 MiB
  above the soft limit; repeated deferred fatals do not keep pushing the
  hard limit up; and AppSec's temporary raise of `memory_limit` around its
  request-end work raises the hard limit with it and its restore brings it
  back.
- **G23.** Every deferred fatal clears the pending state and ends in a
  bailout, even when an installed error callback swallows E_ERROR.
- **G24.** The deferred fatal is raised only at a safe point (the end of a
  window's parse, or a VM interrupt outside a window) and never inside the
  tracer sandbox.
- **G25.** After a refused raise, every allocation outside a window asks for
  another VM interrupt, whatever the usage at that time.
- **G26.** An interrupt that arrives while other extensions' interrupt
  handlers run, with an error pending, raises it at once; this state never
  outlives that call, a bailout or the request.
- **G27.** Whenever AppSec stops enforcing before process shutdown (any
  disarm, or a fatal raised while unarmed with usage possibly above
  `memory_limit`), the engine's limit afterwards is `memory_limit` or the
  finite limit, never the sentinel. Module shutdown, where the process is
  exiting, leaves the limit as it is.
- **G28.** A hard exit runs no application code (no output handler, no
  displayed log line) and ends with exit status 137.
- **G29.** The deferred fatal's message shows the soft limit derived from
  `memory_limit` at the crossing, never AppSec's temporary raised value
  (`ZEND_LONG_MAX`) nor the floor F.
- **G30.** After a deferred fatal, AppSec's RSHUTDOWN logs no "Failed to
  restore memory limit" warning. In a worker that uses ddtrace's
  user-request API, a failed restore at the end of a user request ends the
  worker under a bounded limit.

### Exceptions and limitations

- **Request level delivery.** Exception to G1 and G51: the error is raised at
  the next VM interrupt, after the other extensions' interrupt handlers (pcntl
  signal handlers, Excimer, ddtrace and its profiler). Code that runs in between
  runs above `memory_limit`, up to the hard limit. Inside the tracer sandbox,
  and in internal functions that switch to `EH_THROW` around a call into
  application code, the raise is delayed, so it can come late. If the HTTP
  request's span is already finished when it is raised (for example a FrankenPHP
  request the WAF blocked), it is not tagged and the trace is not kept. A
  crossing in AppSec's RINIT, or in the RINIT of an extension loaded after
  ddtrace.so (except on PHP 7.3 with opcache loaded, section 6), is raised at
  the script's first interrupt, where stock PHP would fail the request startup
  (or, on a partial runtime, end the process). An error
  still pending when no further interrupt can come (other modules' RSHUTDOWN,
  output handlers, session write, the end of the script) is dropped: the request
  succeeds within the hard limit. Coroutine runtimes (Swow, Swoole) can delay
  the raise by one scheduling slice.
- **Raise outside the request level.** Exception to G1: after a deferred
  fatal from a `request_parse_body()` window that the request level did not
  arm, the rest of the request runs unarmed under the finite limit. (No
  application code follows a startup window's deferred fatal.)
- **Displaced interrupt hook.** Exception to G1: if another extension
  replaces `zend_interrupt_function` after startup without chaining to
  AppSec's, a pending error is never raised: the request either grows to
  the hard limit and exits with 137, or the error is dropped at RSHUTDOWN.
  Under the OPcache JIT, compiled code may keep calling AppSec's wrapper
  anyway.
- Exception to G1: if the operating system refuses a very large
  reallocation that has to move the block near the hard limit, the engine's
  own bailout follows, because reallocations are only checked after they
  are forwarded. On systems with default overcommit settings the check after
  the reallocation still ends the process with 137.

The following cases end in a hard exit (status 137) instead of the
deferred fatal; this is expected:

- **Request level, no safe point.** Growth inside one long internal call
  (one `parse_str()` or array builder), or with no VM
  interrupt before the end of the request, can reach `max(L, S + 8 MiB)`;
  so can a single allocation that would cross it, as with upstream's hard
  limit. That limit equals upstream's from S = 8 MiB and is higher below.
- **Timeout at the same interrupt.** When `max_execution_time` expires at
  the interrupt that would deliver a pending error, PHP handles the timeout
  instead, and no new interrupt is requested. A shutdown function that
  keeps allocating then runs under the hard limit and can exit with 137.
  FrankenPHP's force-kill of a stuck thread is such a timeout.
- **Tracer hook closure.** Because the raise is refused inside the tracer
  sandbox, a hook closure that keeps allocating after a crossing can reach
  the hard limit, where stock PHP's fatal would have been swallowed by the
  sandbox. On ZTS, the hard exit ends every thread of the process.
- **memory_limit lowered below usage** (PHP 7.1-7.3, 7.4.0-7.4.20 and
  8.0.0-8.0.7, whose `ini_set()` accepts such a value): the new value
  becomes the soft limit as is, the hard limit derived from it can be at or
  below usage, and the next allocation exits with 137.
- **Skipped RSHUTDOWN** (PHP 7.x): a bailout in the RSHUTDOWN of an
  extension loaded after ddtrace.so, which runs before AppSec's, skips
  AppSec's, so the request stays armed. This cannot happen on PHP 7.3 with
  opcache loaded, where no RSHUTDOWN runs before AppSec's (section 6).
  PHP then restores a `memory_limit` raised at runtime, AppSec adopts it,
  and if cached memory keeps usage above the resulting hard limit, the next
  allocation exits with 137, where stock PHP would fail only when the heap
  grows. This needs all three conditions.
- After a deferred fatal at the request level, a later `request_parse_body()`
  window that crosses again can leave the hard limit below current usage, so
  the next allocation exits with 137 without a second deferred fatal. When F
  plus 8 MiB exceeds the request hard limit, later safe points raise again.

Other expected behaviours:

- **After a deferred fatal.** The request stays armed, so a crossing in
  shutdown functions, destructors or output callbacks is deferred again or
  ends in a hard exit (more often when one internal call has no safe point;
  for example a 200 MiB allocation after the fatal exits with 137). Raised in
  a shutdown function, the deferred fatal skips the later shutdown
  functions, as any fatal does.
- **Swallowed deferred fatal.** Code that catches the bailout and continues
  (ext/soap turns it into a SoapFault inside a SoapClient call that runs
  application code; parallel runs the next task of the same runtime) stays
  armed, and its next crossing is deferred again.
- **Limit after a dropped error.** When `memory_limit` is restored with
  usage above it (mainly after a pending error was dropped), the engine
  gets the finite limit instead, so the rest of shutdown (later
  RSHUTDOWNs, session write, output teardown) is bounded a little above
  `memory_limit`.
- **Sentinel memory_limit.** A `memory_limit` of -2 (PHP 7.0-8.1) or
  18446744073709551614 (8.2+), or 9223372036854775806 on a runtime with
  the full upstream fix, set at runtime is exactly the sentinel, so
  AppSec does not see the change and the previous limits stay in force,
  where stock PHP would have no limit.
- **Ordinary out-of-memory errors.** Whole-request arming applies to every
  request on an unpatched runtime, so an application's own crossing of
  `memory_limit` is deferred too, counted in the deferred metric
  (`phase:runtime`), tagged on the span, and its trace kept.
- On 32-bit builds, the window limit computations saturate once
  `memory_limit` reaches about 1.4 GiB.

## 8. Request data handling

Two parses run in windows: the startup parse of POST, GET and COOKIE data,
and `request_parse_body()` (PHP 8.4+), which builds `$_POST` and `$_FILES`
directly. Other parses (`parse_str()`, `$_SERVER`, `$_ENV`) are not windows;
on PHP 7.1+ the request level covers them. Once an error is pending while
the allocator is armed, three wrappers stop further growth, so the overshoot
is bounded by the item being processed. This happens inside a window, and
also at the request level when PHP registers request variables outside a
window, which only FrankenPHP does: in worker mode it re-imports the
superglobals of each HTTP request through the same `treat_data` and
`input_filter` path as a startup parse.

- **Drop** (`sapi_module.input_filter` wrapper): each later GET, POST or
  COOKIE variable is discarded without being passed to the inner filter
  (ext/filter or the default one); the caller frees the value and registers
  nothing.
- **FILE_START skip** (`php_rfc1867_callback` wrapper): each later file part
  of a multipart body is refused at its start, so PHP skips its body; this
  works with or without ext/session.
- **End of body** (`sapi_module.read_post` wrapper): in a multipart parse,
  the reader reports the end of the body, so the parser finishes only what
  is already buffered (about 5 KiB). The rest is drained or discarded at
  request shutdown.

While a window is armed, no application code runs: the user error handler
is hidden, `display_errors` is 0 and session upload progress is off, all
restored on every exit. A warning is logged once per process when
`filter.default` can expand values or `mbstring.encoding_translation` is
on, because those configurations can use more memory than the hard limit
allows for.

### Guarantees

- **G31.** A request body within `post_max_size` never makes the process
  reach the hard limit.
- **G32.** `$_SERVER`, `$_ENV`, `getenv()` and `parse_str()` values are
  never dropped, and nothing is dropped unless the allocator is armed with
  an error pending.
- **G33.** A dropped variable is absent from both the superglobal
  (`$_GET`, `$_POST`, `$_COOKIE`) and `filter_input()`. Dropping allocates
  nothing.
- **G34.** For a variable that is not dropped, the wrapper returns exactly
  what the inner filter returns (the default filter's result when there is
  none). For input that does not cross `memory_limit`, the superglobals are
  identical with the mitigation on and off.
- **G35.** An rfc1867 callback (session upload progress or a third-party
  one) sees each file part whole (start, data, end) or not at all.
- **G36.** The body reader forwards every read except during an armed
  multipart parse with an error pending, and no unread input is left for a
  later request on the same connection.
- **G37.** No application code runs while a window is armed, and the error
  handler, `display_errors` and session upload progress are restored on
  every exit from a window.

### Exceptions and limitations

- **Displaced input_filter.** Exception to G31: if `input_filter` was
  replaced after startup, a window is still armed but has no per-variable
  drop, so a body within `post_max_size` can reach the hard limit.
- **Truncated superglobals in FrankenPHP worker mode.** When the error
  becomes pending during the re-import of an HTTP request's superglobals,
  the deferred fatal is raised at the next VM interrupt, so the worker's
  handler code that runs before that interrupt sees `$_GET`, `$_POST` and
  `$_COOKIE` without the dropped variables.
- **Constant cap.** Exception to G31: in extreme configurations the window
  cap, not the body size, sets the hard limit; a single field larger than
  about 1 GiB can exit with 137.
- **max_input_vars in the millions.** Exception to G31: hash-table growth
  can reach the hard limit within `post_max_size`.
- **filter.default.** Exception to G31: a `filter.default` that can expand
  values (anything but unset or `unsafe_raw`) has no allowance in the hard
  limit, so a body within `post_max_size` can reach it. A warning is logged
  once per process.
- **mbstring.encoding_translation.** Exception to G31: with it on,
  mbstring's parse of a URL-encoded body allocates about 32 bytes per `&`
  (24 on PHP 7.0) in one call, before `max_input_vars` applies, so a body
  of `&` characters within `post_max_size` can reach the hard limit. A
  warning is logged once per process. Where stock PHP fails the request,
  php-fpm instead loses the worker (a 502 instead of the error page),
  Apache prefork loses a child, and threaded SAPIs (Apache event on ZTS,
  FrankenPHP) can lose a concurrent request.
- **Soft limit below usage** (PHP 8.1.4+). Exception to G31: application
  code can lower `memory_limit` below its own usage (the setter can report
  success without applying it while chunks are cached) and then call
  `request_parse_body()`; the hard limit derived from that soft limit can
  then be at or below usage.
- **Extensions that exit on the error** (hp_uploadprogress_guard).
  Exception to G31 and G36: hp_uploadprogress_guard 3.2+ owns the multipart
  POST handler, so the end of body and the FILE_START skip do not apply
  under it (see also section 10).
- **Part in progress.** The variable or file part being registered at the
  crossing completes, overshooting within the hard limit. A file part keeps
  its temporary file and `$_FILES` entries, with up to about 11 copies of
  its name.
- **Skipped file parts.** Later file parts are skipped at their start, so
  no rfc1867 callback sees them.
- **Unread body.** After a crossing in a multipart parse, the rest of the
  body is left unread. The SAPI discards it (apache2handler) or closes or
  ignores the connection (php-fpm, php-cgi). A keep-alive FastCGI
  connection to php-fpm is not left with unread input: a deferred fatal from
  the startup parse fails request startup and php-fpm force-closes the
  connection and exits the worker; after one from `request_parse_body()`,
  PHP drains the rest of the body at request shutdown.
- **Temp files and fds.** Handled as in stock PHP.
- Exception to G33: multipart fields that are dropped are still passed to
  rfc1867 callbacks (as form data events) with their value. Session upload
  progress is off while windows are armed on unpatched runtimes, so this
  concerns third-party callbacks, and session upload progress only under
  the mode `on` on a patched runtime.
- Drops do not change `max_input_vars` counting or its warnings.
- Exception to G37: a cycle garbage collection triggered during the parse
  could, in theory, run an object destructor while a window is armed.
- As a consequence of G37, frameworks that turn warnings into exceptions
  see no warning from `request_parse_body()` while the mitigation is
  active, and session upload progress does not report for
  `request_parse_body()`.

### Out of scope

- G31 covers only bodies within `post_max_size`. Without a declared
  `Content-Length`, unpatched PHP's multipart parser does not stop reading
  at `post_max_size`, so such a body is not bounded by the input bound and
  can reach the hard limit.

## 9. Stale-state cleanup (CVE-2026-109619)

**Sub-mitigation B** runs in post_deactivate, after each request, when the
mitigation is active and the runtime unpatched. It clears, without freeing
anything, the request-owned engine fields that can be left pointing into
freed memory: the user error and exception handlers and their stacks, the
error-handling mode, the current exception, the recorded errors (PHP 8.1+),
the last fatal error backtrace (8.5+) and the superglobal slots. The object
store is reset later, by AppSec's `sapi_module.deactivate` wrapper, which
runs after every module's post_deactivate, and only once the executor is no
longer active; if that wrapper has been replaced, the object store is reset
immediately in post_deactivate instead. The same wrapper releases the last
error message and file. It calls the inner deactivate handler inside a
protected block, so the resets run even if that handler bails out.

**Sub-mitigation C** turns `session.upload_progress.enabled` off for the
startup POST window and inside `request_parse_body()` windows on unpatched
runtimes, and restores the configured value on every exit. It runs whenever
a window runs, that is, whenever the policy is armed.

### Guarantees

- **G38.** On an unpatched runtime where the mitigation is active, the
  stale request state is reset after every request (the object store and
  the last error after every module's post_deactivate), and session upload
  progress is off during the startup POST parse and `request_parse_body()`
  while armed.
- **G39.** Sub-mitigation B never frees or destroys what it resets, and
  never touches the fields that the upstream fix introduced or
  `PG(modules_activated)`.
- **G40.** The object store is never reset while the executor is active
  (for example the live store of a FrankenPHP worker script), and it is
  reset after every module's post_deactivate when AppSec's deactivate
  wrapper is in place.
- **G41.** Session upload progress is always restored after a window,
  including after a bailout.
- **G42.** Sub-mitigations B and C never run on a patched runtime, whatever
  the mode, and B runs only while the mitigation is active.

### Exceptions and limitations

- **Late last errors.** Exception to G38: a last error set in
  `sapi_deactivate_destroy()` or `php_shutdown_stream_hashes()`, which run
  after AppSec's wrapper, is not released.
- Exception to G38: if AppSec's `sapi_module.deactivate` wrapper has been
  replaced by another extension, the last error is no longer cleared (the
  object store is then reset in post_deactivate).
- Exception to G38: if turning upload progress off fails (no change handler
  for the setting, or a non-stock session module), the parse proceeds with
  upload progress on.
- **FrankenPHP last error.** Worker mode deactivates the SAPI at every HTTP
  request boundary, so the wrapper also clears the last error there, while
  the worker script keeps running: `error_get_last()` in the worker script
  does not see an error raised by the handled request. This is memory-safe;
  recent FrankenPHP versions clear the last error at that point themselves.
- **Skipped file parts** (upload progress). Session upload progress does not
  work on an unpatched runtime while the mitigation is armed (startup POST
  parse and `request_parse_body()`).

## 10. Coexistence with other extensions

AppSec captures each of the following hook slots once, at the end of
startup (after every module has initialised and after the profiler built
into ddtrace.so has installed its own), saving the previous value and
chaining to it. It writes them again only at module shutdown, and only if
they still hold its wrapper.

| Hook | PHP versions | Purpose | At module shutdown |
|---|---|---|---|
| `sapi_module.treat_data` | all | starts the startup window | restored if still ours |
| `sapi_module.input_filter` and `input_filter_init` | all | drop; captured together so ext/filter keeps working | restored if still ours |
| `php_rfc1867_callback` | all | FILE_START skip (also captured when empty) | set to empty if still ours; ext/session reinstalls its callback when it starts |
| `sapi_module.read_post` | all | end of body | restored if still ours |
| `sapi_module.deactivate` | all | object store and last error reset | restored if still ours |
| `request_parse_body()` handler | 8.4+ | `request_parse_body()` window; the function is looked up by name each time | saved handler forgotten |
| `zend_interrupt_function` | 7.1+ | raises deferred errors; installed outermost | restored if still ours |
| `gc_mem_caches()` handler | 7.1-8.3 | lets `gc_mem_caches()` free memory while armed; installed only if the current handler is in the PHP binary (the engine's own, or that of an extension compiled into PHP) or in ddtrace.so (the Datadog profiler's, which always calls the handler it replaced), or if `dladdr()` cannot tell | restored if still ours |
| `zend_error_cb` | all (8.0: installed slightly later, after opcache's) | monitor detection; always chains | restored if still ours |
| Allocator handlers | per arming (section 6) | enforce limits | heap reset to plain zend_mm |

After every request in which the mitigation is active, AppSec checks
whether each captured slot still holds its wrapper. The first replacement of
each slot is logged once per process, to the AppSec log only: "X was
replaced after startup by another extension; ... unless that extension
chains to ours". phpinfo shows each hook as Installed, Replaced or Not
installed while the mitigation is active. "Replaced" only means that
AppSec's wrapper is no longer on top; nothing is repaired and arming does
not depend on it.

Known interactions:

| Extension or runtime | Interaction |
|---|---|
| Datadog profiler | Part of ddtrace.so (the standalone `datadog-profiling.so` cannot be loaded with it). Its allocator handlers are installed in its RINIT, before AppSec's, and removed in its RSHUTDOWN, after AppSec's, so they always nest. Its `gc_mem_caches()` wrapper sits under AppSec's, so `gc_mem_caches()` frees memory with any profiling settings. |
| memprof, Snuffleupagus | Mitigation off for the process (section 4). |
| Xdebug (develop or step_debug; 2.x with `default_enable=1`) | Replaces `zend_error_cb` every request without chaining: monitor mode reports nothing; enforcement is unaffected. |
| tideways_xhprof, SPX (PHP 8.4+) | Re-install handlers with the older setter, which clears AppSec's gc and shutdown handlers (see below). |
| Swow, Swoole | Can delay a deferred fatal by one scheduling slice. Swoole's leak detection only applies to CLI-like SAPIs, which are excluded. |
| ext/filter | Captured together with its init hook; an expanding `filter.default` is warned about. |
| mbstring | `encoding_translation` is warned about. |
| session | Upload progress off while armed; its upload callback is captured and set back to empty at shutdown, so an Apache graceful restart does not chain to stale code. |
| opcache | Preload: see section 5 and section 11. The JIT may keep calling AppSec's interrupt wrapper after it was replaced. |
| pcntl, Excimer | Their interrupt handlers run first; a signal or timer during them raises at once. |
| ddtrace | No raise inside its sandbox; deferred fatals are tagged on its spans. |
| Error callbacks that swallow E_ERROR | AppSec bails out explicitly after its fatal. |
| colopl_bc, hp_uploadprogress_guard | End the process on the memory error (see below). |
| Elastic APM 1.11-1.17 | Object store reset is delayed until after every module's post_deactivate, so it does not crash. |
| parallel | After a swallowed deferred fatal the next task of the same runtime runs; the error does not move to later tasks. |
| FrankenPHP | Worker mode supported (sections 5, 6, 9). |
| NGINX Unit, `php -S` before 8.1 | Leftover state cleared at the next request (section 6). |

### Guarantees

- **G43.** Hook slots are written only at the end of startup and at module
  shutdown. Installation refuses a slot that already holds AppSec's
  wrapper, so no wrapper ever forwards to itself and AppSec never chains
  its allocator handlers over its own.
- **G44.** Nested `gc_mem_caches()` wrappers each restore the allocator
  state they found, and the request is still armed after the call.
- **G45.** No pointer to the `request_parse_body()` or `gc_mem_caches()`
  function is kept across requests (so `disable_functions` cannot leave a
  dangling one).
- **G46.** The `zend_error_cb` wrapper always calls the handler it
  replaced.
- **G47.** Replaced-hook reports never go to PHP error reporting or the
  response.
- **G48.** After module shutdown, no hook slot that held an AppSec wrapper
  still holds one, except the `request_parse_body()` function entry (PHP
  8.4+): only its saved handler is forgotten, and the entry keeps AppSec's
  wrapper until the function table is destroyed.

### Exceptions and limitations

- **Displaced hooks** (other hooks). A replaced hook is not repaired; a
  replacement that chains to AppSec's wrapper is reported the same way.
  The request stays armed whatever happens to `zend_interrupt_function` (see
  **Displaced interrupt hook**, section 7) or to `gc_mem_caches()`, whose
  replacement, if it does not chain to AppSec's wrapper, may free nothing.
- **gc_mem_caches() replaced first.** When another extension replaced the
  `gc_mem_caches()` handler before AppSec's startup capture (PHP 7.1-8.3),
  AppSec's is not installed, and `gc_mem_caches()` frees nothing while a
  request is armed unless that extension does the equivalent work. Only a
  handler in another shared object is recognised as such. An extension
  compiled statically into PHP is not told apart from the engine, nor is any
  extension when `dladdr()` fails: AppSec's wrapper is installed over its
  handler and calls it with the heap's custom handlers turned off while
  AppSec's allocator handlers are in the chain, so whether `gc_mem_caches()`
  frees memory, and whether that handler copes, depends on the extension.
- **Old handler setter.** Profilers that put AppSec's allocator handlers
  back with `zend_mm_set_custom_handlers()` instead of its `_ex` variant
  (tideways_xhprof, SPX; PHP 8.4+) clear AppSec's gc and shutdown
  handlers: `gc_mem_caches()` frees nothing for the rest of the request.
  Protection is unaffected.
- **Extensions that exit on the error.** The deferred fatal starts like the
  engine's ("Allowed memory size of"), so extensions that end the process
  on that error end it on the deferred fatal too; protection holds.
  colopl_bc (`abort_on_memory_error`, on by default) calls `_exit(255)`.
  hp_uploadprogress_guard 3.2+ calls `_exit(137)` when no PHP frame is
  executing, as for a fatal from the startup parse.
- An extension that takes a slot again on every request and chains to the
  previous value would run its callback twice; one that overwrites a slot
  without chaining turns that part of the mitigation off. No known extension
  does either.
- Replaced hooks are detected only while the mitigation is active.

### Out of scope

- **Extensions that need plain zend_mm.** With AppSec's handlers installed
  for the whole request, `is_zend_mm()` is false, so an extension that
  requires it stops working while armed (for example memprof's runtime
  `memprof_enable()` would not profile; memprof is excluded anyway).
- **Handlers reset after a bailout.** When a bailout skipped AppSec's
  RSHUTDOWN, the heap is reset to plain zend_mm rather than to the handlers
  found at installation, so a foreign extension below AppSec that keeps its
  handlers across requests loses them. The Datadog profiler installs its own
  again at its next RINIT.
- **Profiler recursion.** The Datadog profiler stacked over AppSec's
  handlers, which happens only when they stayed chained under a third
  extension that keeps its allocator handlers across requests, recurses
  without end. This has only been seen
  with a synthetic test extension; the loop is inside the profiler.
- **Exotic extensions**: anything beyond chaining what was captured at
  startup.

## 11. Monitor mode, reporting and telemetry

In the monitor state nothing is armed. AppSec's `zend_error_cb` wrapper
inspects fatal errors: messages starting with "Allowed memory size of" are
reported as `memory_limit`, those starting with "Out of memory" as
`out_of_memory`; AppSec's own deferred fatal is ignored. The *phase* is
`startup` while module request initialisation has not finished,
`request_parse_body` inside that function (PHP 8.4+), and `runtime`
otherwise. The error is tagged on the span, the trace is kept, a line is
logged at info level, and the error then continues to the engine as usual.

Span tags go on the request's root span when one exists (there is none before
the tracer's part of the ddtrace RINIT, so, on PHP 7.3 with opcache loaded,
none in any other extension's RINIT, section 6). Each tag also keeps the
trace, as an AppSec event does.

| Tag | Value | When |
|---|---|---|
| `_dd.appsec.php2shell.deferred` | `startup`, `request_parse_body` or `runtime` | a deferred fatal (block decision or mode `on`); `startup` only on the startup span |
| `_dd.appsec.php2shell.startup_dropped` | count | the startup span, when startup fatals of the pool could not be saved or were lost |
| `_dd.appsec.php2shell.memory_error` | `memory_limit` or `out_of_memory` | monitor state, native error |
| `_dd.appsec.php2shell.phase` | `startup`, `request_parse_body` or `runtime` | monitor state, native error |

**Startup span.** A deferred fatal from the startup window fails the request
startup before any module RINIT, so that request never has a root span and
cannot send one. Its span is sent by a later request instead:

- **Saving.** Before raising (section 7), AppSec builds `$_SERVER` and copies
  into its pool's *startup span slot* what the span needs: the request's
  start time and the time of the raise, the method, the raw URI with its
  query string, the `HTTP_*` headers, the `$_SERVER` entries that give the
  scheme (`HTTPS`), the host without a `Host` header (`SERVER_NAME`) and the
  client address without a client IP header (`REMOTE_ADDR`), the service,
  env and version resolved for this request, the breached limit, and the
  identity of the failed process: its process id, its runtime id and, when
  the failed request reports a hostname, that hostname. A slot holds one
  event and is 64 KiB. The request's own fields and those three `$_SERVER`
  entries, all short, come first, then the distributed tracing headers, then
  the URI and query string, which are cut if they do not fit in the room
  left, then the headers that the root span's properties read (host, user
  agent, referer, client IP headers), then every other header. A header that does not fit is
  left out whole, never cut. Nothing is saved when tracing or root span
  generation is off for the failed request.
- **The slots** are one anonymous shared mapping created when the module
  starts, before php-fpm or Apache fork their workers: a directory and 64
  slots, one per pool. A pool is told apart by the SAPI, the effective user
  and, under php-fpm, the pool name read from the worker's process title. At
  its first activation (section 6), after php-fpm or Apache switched to the
  pool's user and before any request data is parsed or any user code runs,
  each forked process finds or claims its pool's directory entry and then
  unmaps the directory and every other pool's slot, as php-fpm does with
  the scoreboards of other pools. A pool's workers and a process's threads
  therefore share one slot, and once a worker has had its first activation
  it can no longer read, send or forge another pool's events (see **Startup
  span, isolation** for what holds before that). A server that handles
  requests in the process that created the mapping (`php -S`, FrankenPHP,
  `httpd -X`) binds that process without
  unmapping anything, because the processes it forks later still need the
  whole mapping. A failed request claims its slot only if it is empty, or if
  a process left it being written or read for 10 seconds (it died or was
  stopped). Every startup fatal of a request with tracing and root span
  generation on that is not saved (the slot was full or busy, or `$_SERVER`
  could not be built), and every saved event lost before its span was added
  (its writer, or the reader that had taken it, died; it is corrupt; or the
  span could not be created), increments the slot's dropped count instead;
  the metric still counts each of them.
- **Sending.** The next request of the pool that reaches AppSec's RINIT with
  tracing and root span generation on takes the event, whatever AppSec's own
  state for that request, and empties the slot. It creates the failed
  request's span as the root span of a separate trace, in a new span stack
  (as `DDTrace\start_trace_span()` does), with the operation name
  `web.request.startup_failure` and type `web`, the saved start time and
  duration, service, URL (query string obfuscated as usual), header tags,
  client IP (whatever `DD_TRACE_CLIENT_IP_ENABLED` says, as on AppSec's
  traces), incoming distributed tracing context, and the failed process's
  process id, runtime id and hostname (none if it reported none); the tags
  `_dd.appsec.php2shell.deferred=startup` and, if non-zero,
  `_dd.appsec.php2shell.startup_dropped`; `error.type` and `error.message`
  for the deferred fatal; and status 500. It closes the span at once; the
  carrier's normal flush sends it, also when the carrier runs no PHP code
  (php-fpm's status or ping page, a missing script), whose own trace is not
  sent; the AppSec keep keeps it, and it does not use up the allowance of
  standalone AppSec (`DD_APM_TRACING_ENABLED=false`). The operation name is
  not `web.request` because the span's duration includes reading and
  parsing the request data, which no other span covers, so trace metrics
  keep it apart from the endpoint's `web.request` series.

Metrics (AppSec namespace, counts), flushed synchronously:

| Metric | Tags |
|---|---|
| `appsec.php2shell.soft_limit_deferred` | `mode:auto` or `mode:on`; `phase:startup`, `phase:request_parse_body` or `phase:runtime` |
| `appsec.php2shell.hard_limit_exit` | `mode:auto` or `mode:on` |

Log lines (AppSec log):

- window deferred fatal (warning): "request data parse exceeded
  memory_limit (N bytes); dropped N request variables; raising deferred
  out-of-memory error at a safe point (phase:...)". The count covers the
  variables dropped in the current parse; skipped file parts are not
  counted.
- runtime deferred fatal (warning): "allocation after request startup
  exceeded memory_limit (N bytes); dropped N request variables; raising
  deferred out-of-memory error at a VM interrupt (phase:...)". The count
  covers the variables dropped since the last window armed or PHP last
  began registering request variables. Outside a window, variables are
  dropped only during FrankenPHP's re-import of the superglobals.
- hard exit: one line on standard error (section 7), no AppSec log line.
- replaced hooks, "memory manager handlers were replaced", the
  `filter.default` and `mbstring.encoding_translation` warnings, the
  incompatible extension warning, unknown mode or decision values; debug
  lines for decision changes and limit fallbacks; the monitor info line.

phpinfo shows, in the ddtrace module's Application Security table,
PHP2Shell rows, starting with "PHP2Shell mitigation (CVE-2026-109618/109619)":
Active or Inactive, the mode, whether the runtime is patched, the WAF
decision (`n/a` unless the mode is `auto`), the incompatible extension if
any, and, while active, one row per hook. With `datadog.appsec.testing` on,
test-only functions are registered: `php2shell_mitigation_status()`,
`php2shell_mitigation_request_status()`, `php2shell_mitigation_set_allowed()`
and `php2shell_mitigation_post_deactivate_reset()`; the environment
variables `DD_APPSEC_TESTING_PHP2SHELL_RUNTIME` and
`DD_APPSEC_TESTING_PHP2SHELL_DECISION` override the detected runtime and the
initial decision.

### Guarantees

- **G49.** The monitor state never arms the allocator, and monitor detection
  never reports AppSec's own deferred fatal.
- **G50.** In the monitor state on a full runtime, a native "Allowed memory
  size" or "Out of memory" fatal raised while a root span exists, in any
  phase, is tagged with `_dd.appsec.php2shell.memory_error` and
  `_dd.appsec.php2shell.phase`, and the error is then handled by the engine
  as without the mitigation.
- **G51.** Every deferred fatal increments
  `appsec.php2shell.soft_limit_deferred` and every hard exit increments
  `appsec.php2shell.hard_limit_exit`, flushed before the request or process
  ends. A deferred fatal raised while a root span exists is tagged with
  `_dd.appsec.php2shell.deferred`. A deferred fatal from the startup window
  of a request with tracing and root span generation on is reported by a
  startup span that a later request of the same pool sends: its own span
  if its event was saved and sent, otherwise as part of the dropped count
  of the next startup span of that pool. A hard exit is never tagged.
- **G52.** The phase values of the span tags equal the phase tag values of
  the metric.
- **G53.** Without a root span nothing is tagged. Tags are added, never
  replaced (the first error of a request wins). A tagged error keeps the
  trace when the trace is sent.
- **G54.** Emitting and flushing the metrics never triggers a
  `memory_limit` bailout.
- **G55.** The test-only functions exist only with
  `datadog.appsec.testing` on, and production code paths read no testing
  setting.
- **G56.** Saving a startup event runs no tracer code and reads no request
  data superglobal but `$_SERVER`; it writes at most the slot's size, and
  never blocks on another worker. It runs under the finite limit, so a native
  memory error while it builds `$_SERVER` is an ordinary fatal error, which
  goes through PHP's error callbacks, the tracer's included, like any other
  fatal raised there; this is not counted as saving running tracer code. A
  startup event's span is sent at most once, and only by a request with
  tracing and root span generation on.
  Saving and sending use only the slot of the process's pool: from its
  first activation on, a forked process maps neither the directory nor any
  other pool's slot.

### Exceptions and limitations

- **Displaced hooks** (Xdebug). Exception to G50: Xdebug in develop or
  step_debug mode (2.x with `default_enable=1`) replaces `zend_error_cb` at
  every request without chaining, so monitor mode reports nothing.
- Exception to G50: on PHP 8.0, the `opcache.preload` script is not
  monitored. Preload runs at server startup with no request span, so
  nothing would be reported anyway.
- **Monitor on a partial runtime.** Exception to G50: the end-of-life fix
  writes the fatal error to standard error and ends the process with exit
  status 1 without calling `zend_error_cb`, so the error is not tagged,
  logged or counted, and the trace is lost.
- **Monitor mode** ("Out of memory" reports). Exception to G50: an "Out of
  memory" error is reported while zend_mm's overflow flag is set; if the
  operating system refuses one of the small allocations of the report, zend_mm
  ends the process with `exit(1)` instead of the fatal error.
- Exception to G51: a deferred fatal raised after the request's span was
  finished or serialized is not tagged (metrics only); see **Request level
  delivery** in section 7.
- Exception to G53: after a switch of the active span stack, the tag and the
  trace keep can land on different traces.
- **Startup span not sent.** Exception to G51: the startup span is lost
  when no later request of the pool reaches RINIT with tracing on before the
  pool stops, when the carrier ends, or turns tracing off, before its flush
  (the event is then not counted as dropped either), and with php-cgi run
  without a process manager (each request is a new process). It is delayed
  until such a request comes; the metric is not.
- **Startup span, one slot per pool.** Exception to G51: of the startup
  fatals that are not saved, or whose event is lost, only the count is
  reported, on the next startup span of the pool.
- **Startup span, more than 64 pools.** Exception to G51: the directory has
  64 entries, and they are never freed while the mapping lives. With more
  than 64 pools under one php-fpm master (or more than 64 users under one
  Apache with mpm-itk), the pools that bind after the directory is full get
  no slot: their startup fatals are only counted by the metric, and a
  warning is logged once.
- **Startup span, no mapping.** Exception to G51: if the slots cannot be
  mapped at module startup (or the page size does not divide 64 KiB), or a
  forked process cannot unmap the other pools' slots, a warning is logged
  and no startup span is saved or sent by the processes concerned; the
  metric still counts.
- **Startup span, reload or restart.** Exception to G51: a reload or
  restart of php-fpm or Apache maps new slots, so an event still pending in
  the old ones when the old workers stop is lost, with its dropped count.
- **Startup span, shared slots.** Exception to G56: some pools share a slot,
  and so can carry each other's events. Under Apache mod_php, every virtual
  host served by one user shares a slot (with mpm-itk, there is one per
  virtual host user). A php-fpm pool whose title cannot be read (a chroot
  pool without `/proc`) gets the slot of its user, shared with that user's
  other such pools. Two pools of one user also share a slot when the 32-bit
  hash of their names collides, or when their names only differ after the
  first 240 bytes or so (the title read is bounded). A process that changes its user between requests
  (mod_ruid2) saves and sends events only while it runs as the user of its
  first request.
- **Startup span, isolation.** Exception to G56: between the fork and its
  first activation, a worker still maps the whole region, every pool's slot
  included. No request data is parsed and no user code runs in that window,
  but another process running as the same user can read the worker's memory
  there, through `ptrace` or `/proc/<pid>/mem`, when the worker is dumpable
  and `kernel.yama.ptrace_scope` allows it. Across different users this is
  not possible, so the isolation between pools of different users holds
  throughout; between pools of the same user it holds only after each
  worker's first activation.
- **Startup span, configuration.** The startup span uses the failed
  request's service, env and version, and the failed process's process id,
  runtime id and hostname, but the carrier's other tracer settings (header
  tags, obfuscation, agent) and its connection to the agent. It reports the
  client IP whatever either request's `DD_TRACE_CLIENT_IP_ENABLED` says.
- **Monitor mode** (startup parse). A native memory error in the startup
  parse, before any span exists, is only logged at info level; no metric
  counts it, and no startup span is saved for it.
- The trace keep has no rate limit: an application that crosses
  `memory_limit` on every request keeps every trace.

## 12. Test suite

The tests live in `appsec/tests/extension/php2shell_mitigation_*.phpt`, with
shared helpers in `appsec/tests/extension/inc/php2shell_*.php`. Because the CLI
SAPI is excluded, scenarios run in a php-cgi or `php -S` child process (or under
`--CGI--`). A test-only extension, `p2s_stub.so` (never packaged), simulates
foreign extensions loaded before or after ddtrace.so: hook replacement,
allocator handlers, bailouts in RINIT or RSHUTDOWN, error-callback swallowing
and more. A test, or a case of one, that needs the stub to run its RINIT
after AppSec's or its RSHUTDOWN before AppSec's skips itself on PHP 7.3 with
opcache loaded, where no extension does (section 6). CI runs these in the
`make xtest` matrix, which builds ddtrace.so with AppSec but without the
profiler; a job "appsec php2shell partial runtime" runs
them on a real partial runtime (Ubuntu jammy's PHP 8.1); "appsec integration
tests (php2shell profiler)" runs the Gradle suite `Php2ShellProfilerTests`
against a ddtrace.so built with the profiler, with allocation profiling on (PHP
8.3 and 8.5); the Gradle suites `Php2ShellMitigationTests`,
`Php2ShellProfilerTests` and `Php2ShellFrankenphpWorkerTests` cover end-to-end
behaviour, and `Php2ShellMitigationTests` (php-fpm) and
`Php2ShellStartupSpanModTests` (Apache mod_php) the startup span: a failed
request followed by a normal one (including the failed process's process id,
runtime id and hostname), a burst of failures, a carrier with root span
generation off, and a failed request with root span generation off (the
shared cases are in the trait `Php2ShellStartupSpanCases`);
`Php2ShellMitigationTests` also covers two pools of
different users and a third of the same user as the first (each pool sends
only its own events, and each worker maps only its own slot), a carrier that
runs no PHP code (a missing script), and standalone AppSec; the helper has unit tests in
`appsec/helper-rust/src/client.rs`.

### Rules for tests

These rules apply to the test suite, not to the mitigation's behaviour, so
they are not guarantees in the sense of section 13; a change that breaks one
is a defect in the tests.

- `php2shell_mitigation_patched_detection.phpt` never skips: it
  fails on any release at or after the first fixed one of its minor version
  when the runtime is not detected as patched.
- No PHP2Shell test exercises the mitigation inside the CLI test
  process.
- Tests check observable outcomes (deferred fatal, hard exit,
  native error, normal completion; phpinfo rows; messages; metrics), never
  internal values such as the growth factor or chain lengths; tests that
  expect a native bailout use a single very large field.

## 13. Is it a bug?

To classify a report:

1. Find the section the behaviour belongs to.
2. If the report shows that a guarantee (G*n*) does not hold, and no
   exception that names that guarantee, and no "Out of scope" item, covers
   the situation, it is a **bug**.
3. If the situation matches a listed exception, limitation or out-of-scope
   item (or an entry of the coverage list in
   `appsec/src/extension/php2shell_mitigation.c`), it is **expected
   behaviour**: answer with the name of that entry.
4. If it matches neither, it needs a decision by the maintainers. Describe
   which of the goals of section 4 it affects and in which configuration.

A crash, memory corruption or a native bailout in the middle of an insertion
in a covered configuration is always worth reporting, as is any exit status
137 for a body within `post_max_size` that none of the exceptions to G31
explains.

## 14. Known issues in other software

The following problems were observed while testing the mitigation. They
are not caused by it, but can show up next to it.

- PHP 8.5.10's tracing JIT can take a wrong branch after a timer VM
  interrupt. A fix for php-src is in preparation; newer 8.5 releases have
  not yet been re-checked.
- The Datadog profiler (part of ddtrace.so) with the PHP 8.5 tracing JIT:
  crashes (SIGSEGV) and assertion failures.
- The Datadog profiler's `gc_mem_caches()` wrapper changes the allocator state
  even when called with arguments (where the original only reports an error);
  with allocation profiling on, on PHP 7.3/7.4, the resulting warning can run a
  user error handler that bypasses AppSec's handlers.
- The Datadog profiler recurses without end when stacked over AppSec's
  handlers, which happens only when they stayed chained under a third
  extension that keeps allocator handlers across requests, and can
  chain over itself if its handlers are restored after it gave up on a
  request.
- Swow (development branch) aborts on PHP 8.5 ZTS and leaks span stacks.
- The function JIT leaks a 40-byte string in `sapi_off.phpt`.
- FrankenPHP 1.4.4 panics on a fatal error during request startup.
- Tideways and SPX crash after another extension's bailout in RINIT or
  RSHUTDOWN; Tideways also crashes with `opcache.preload`.
- tideways_xhprof and SPX (PHP 8.4+) re-install allocator handlers with the
  older setter, which clears other extensions' gc and shutdown handlers.
- Snuffleupagus's upload hook uses one variable both for the saved original
  and for "already hooked", which leads to recursion when another extension
  wraps the same hook; its INI protection turns setting changes made by
  other extensions into bailouts.
- Swoole 6.2's `swoole.leak_detection` re-installs the heap handlers on
  every allocation.
- Xdebug (develop or step_debug mode; 2.x with `default_enable=1`) replaces
  `zend_error_cb` on every request without chaining to the previous handler.
- Elastic APM 1.11.0-1.17.0 releases recorded objects in post_deactivate,
  which crashes in any module order on a patched runtime; fixed in 1.17.1.
- PHP 7.0 to 7.3.16 debug builds loop forever in the leak checker over the
  leaks of `php -S` after a failed request startup.
- Stock PHP: the `parse_str` user-handler use-after-free; a crash at the end
  of session upload progress on 7.0/7.1 with `cleanup=0`; one-argument
  `parse_str` writing in place (bug #77439, 7.0-7.3.1);
  `zend_set_memory_limit()` accepting a limit below usage (bug #81070);
  on PHP 7.0-7.2, removing custom allocator handlers leaves the heap marked
  as custom; opcache 8.0 installs its error callback on top permanently.
