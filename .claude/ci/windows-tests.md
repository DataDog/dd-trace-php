# Windows Tests

**WARNING**: THIS FILE HAS NOT YET BEEN REVIEWED

## CI Jobs

**Source:**
- `.gitlab/generate-tracer.php` — defines `windows test_c`
- `.gitlab/generate-package.php` — defines `compile extension windows`, `package extension windows`, `verify windows`
- `.gitlab/generate-common.php` — `windows_git_setup()` and `windows_git_setup_with_packages()` helpers
- `dockerfiles/verify_packages/verify_windows.ps1` — smoke-test script

| CI Job | Pipeline | What it does |
|--------|----------|--------------|
| `windows test_c: [{ver}]` | tracer | Builds NTS `php_ddtrace.dll` with `phpize`+`nmake`, runs `.phpt` extension tests |
| `compile extension windows: [{ver}]` | package | Builds NTS + ZTS `php_ddtrace.dll`; produces `.dll` + `.pdb` debug symbols |
| `package extension windows` | package | Assembles Windows DLLs into `dbgsym.tar.gz` release archive |
| `verify windows` | package | Installs packaged extension via Chocolatey, runs `verify_windows.ps1` smoke tests |

Runner: `windows-v2:2019`
Image: `registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-{ver}_windows`
Matrix: PHP 7.2+

## What It Tests

`windows test_c` starts `httpbin-windows` and `php-request-replayer-*-windows`
service containers, builds `php_ddtrace.dll` from source inline (no pre-built
artifact needed), then runs `run-tests.php` against `tests/ext/`.

`compile extension windows` builds both NTS and ZTS DLLs; the Rust/libdatadog
build in `x64/Release/target` is moved to `x64/Release_TS/target` so ZTS can
reuse it without recompiling.

## Local Reproduction

Requires a Windows host with Docker configured for Windows containers. Not
reproducible on Linux/macOS.

```powershell
docker pull registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.3_windows

git config --global core.longpaths true
git config --global core.symlinks true
git clone https://github.com/DataDog/dd-trace-php .

$CONTAINER = "ddtrace-windows-test"
docker run -v ${pwd}:C:\Users\ContainerAdministrator\app -d --name $CONTAINER `
  registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-8.3_windows ping -t localhost

# Build
docker exec $CONTAINER powershell.exe "cd app; switch-php nts; C:\php\SDK\phpize.bat; .\configure.bat --enable-debug-pack; nmake"

# Run all extension tests
docker exec $CONTAINER powershell.exe `
  'cd app; C:\php\php.exe -n -d memory_limit=-1 -d output_buffering=0 run-tests.php -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP --show-diff -p C:\php\php.exe -d "extension=${pwd}\x64\Release\php_ddtrace.dll" "${pwd}\tests\ext"'
```

### Single test

```powershell
docker exec $CONTAINER powershell.exe `
  'cd app; C:\php\php.exe -n -d memory_limit=-1 run-tests.php --show-diff -p C:\php\php.exe -d "extension=${pwd}\x64\Release\php_ddtrace.dll" "${pwd}\tests\ext\sandbox\exception_in_original_call.phpt"'
```

### Cleanup

```powershell
docker stop -t 5 $CONTAINER && docker rm -f $CONTAINER
```

## Gotchas

- **`GIT_STRATEGY: none` on all Windows jobs.** `windows_git_setup()` manually
  wipes the workspace and re-clones because GitLab's default checkout fails on
  Windows with deep symlink/junction paths.

- **`windows_git_setup_with_packages()` preserves artifacts.** Jobs receiving
  artifacts (e.g. `verify windows`) move `packages/` to `%TEMP%` before the
  workspace wipe and restore it after.

- **`windows test_c` builds the DLL inline** — there is no separate compile
  prerequisite in the tracer pipeline for Windows.

- **`verify windows` installs Chocolatey inline.** Requires outbound HTTPS from
  the runner. If Chocolatey's CDN is unreachable the job fails immediately.

- **Windows `switch-php` is a PowerShell script**, not the Linux bash version —
  it configures `C:\php\` rather than symlinking into `/usr/local/bin/`.

- **CGI-SAPI `.phpt` tests can hang the whole job on PHP 7.2/7.3.** CGI tests
  (`--GET--`/`--POST--`) run their SKIPIF/CLEAN under `php-cgi`, not the CLI. On
  PHP 7.2/7.3, a ddtrace log line emitted during a CGI request blocks forever on
  a synchronous buffered stderr write, because the Windows CGI stderr pipe is
  never drained ("On Windows cgi skips stderr output"). Only tests that enable
  ddtrace logging (e.g. raising `datadog.trace.log_level` above the default
  `error`) hit it; the job then dies at the full timeout instead of a FAIL.
  7.4+ are unaffected. When `windows test_c` fails on 7.2/7.3, check duration
  before the FAIL lines — see
  [diagnosing-failures.md](diagnosing-failures.md#determine-the-failure-mode-first-hang-vs-test-failure).
