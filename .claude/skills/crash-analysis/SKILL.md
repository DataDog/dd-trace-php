---
name: crash-analysis
description: >-
  Analyze a dd-trace-php crash report (event.json) to identify the crashing
  binary, correlate stacktraces to source code, and determine root cause.
  Use when investigating wild crash reports from crash tracking.
argument-hint: <path-to-event.json>
allowed-tools: Bash Read Grep Glob Agent
effort: max
---

# Crash Analysis

Systematically analyze a dd-trace-php crash report to identify the root cause.

## Input

The user provides a crash event JSON file (path via `$ARGUMENTS`, or pasted
inline). If no path is given, ask for it. Save pasted JSON to a temporary file.

## Phase 1 — Triage (extract all key facts before any analysis)

Extract and print a summary table with these fields. Use `jq` against the event
file.

| Field | Command |
|-------|---------|
| Library version | `jq -c .metadata.library_version $EVENT` |
| Signal | `jq -c .sig_info $EVENT` |
| Native stacktrace | `jq -c .error.stack.frames $EVENT` |
| PHP stacktrace | `jq -c .experimental.runtime_stack.frames $EVENT` |
| Mapped files | `jq -c '.files["/proc/self/maps"]' $EVENT` |
| Registers | `jq -c '.ucontext // .experimental.ucontext' $EVENT \| .claude/parse_ucontext.py` |
| PHP version | `jq -r '.language_version // (.metadata.tags[] \| select(startswith("runtime_version:")) \| split(":")[1])' $EVENT` |

> **Note:** `parse_ucontext.py` only supports amd64. On aarch64, skip this step
> and read register values directly from `.ucontext` (or `.experimental.ucontext`
> in older events).

From the mapped files, determine, one by one:

- **Process under analysis**: we expect the crashing process to be one of two:
  1) process with PHP SAPI (could be `php`, `php-fpm`, `httpd`/`apache2`,
  `frankenphp`) or 2) sidecar.

  Processes with PHP are identified by the first loaded module (e.g. fpm,
  apache), by the fact PHP extensions are loaded (including ddtrace) or by the
  presence of a PHP stack in `.experimental.runtime_stack`.

  The sidecar process typically appears as `/memfd:spawn_worker_trampoline
  (deleted)` (though not necessarily). If AppSec is loaded, you will also find
  `libddappsec-helper.so` / `libddappsec-helper-rust.so` loaded
- **Products loaded**: look for `ddtrace.so`, `ddappsec.so`, `datadog-profiling.so`
- **SSI mode**: check for `libddtrace_php.so` and `dd_library_loader.so` — if present, the process is running the SSI (Single-Step Instrumentation) package. See [SSI architecture](#ssi-architecture) below.
- **OS/arch**: architecture (x86_64 or aarch64)
- **libc**: GNU (`ld-linux-x86-64.so`) or musl (`ld-musl-x86-64.so`)
- **ZTS or NTS**: if it's a PHP process (not sidecar) check the extension
  directory path in the maps — `no-debug-zts-XXXXXXXX` means ZTS,
  `no-debug-non-zts-XXXXXXXX` means NTS. Ondřej Surý packages (Debian/Ubuntu)
  use `/usr/lib/php/<api_version>/` with no ZTS/NTS marker — these are always
  NTS because the PPA does not publish ZTS builds. If unclear from the maps,
  infer from the SAPI: `php-fpm` is NTS, `frankenphp` is ZTS, Apache
  (`httpd`/`apache2`) could be either. When ambiguous, download both ZTS and
  NTS binaries and check which one's symbol addresses match the crash IPs.

  If the process is sidecar, ZTS/NTS mode cannot be easily inferred. For the
  purposes of identifying the binary, there are two possibilities: 1) SSI: the
  distinction is irrelevant because sidecar lives in `libddtrace_php.so`. 2)
  unified ddtrace.so: sidecar is embedded in ddtrace.so, so there may be a need
  to download both versions and try in turn.

### SSI architecture

When the Datadog SSI package is installed, the process loads **four** binaries instead of one monolithic `ddtrace.so`:

| Binary | Typical text size | Role |
|--------|------------------|------|
| `dd_library_loader.so` | ~28 KiB | Zend extension (loaded via `zend_extension=`); bootstraps everything else |
| `libddtrace_php.so` | ~10 MiB | Shared library with sidecar, crashtracker, and Rust components; loaded by the loader with `RTLD_GLOBAL` |
| `ddtrace.so` (SSI standalone) | ~750 KiB | PHP extension with most tracer logic; much smaller than monolithic ddtrace.so |
| `ddappsec.so` | ~630 KiB | AppSec extension; same binary for SSI and non-SSI |

(Sizes vary across versions, but should be of those orders of magnitude)

Loading order (the loader is a Zend extension and fires before any `extension=` module):
1. `dd_library_loader.so` MINIT fires
2. Loader calls `dlopen(libddtrace_php.so, RTLD_NOW|RTLD_GLOBAL)`
3. Loader calls `zend_register_internal_module` for the SSI `ddtrace.so`
4. PHP processes `extension=` directives; any `extension=ddtrace.so` pointing elsewhere is rejected as duplicate

If a non-SSI `ddtrace.so` (e.g. a vendor-bundled extension) is also listed in `extension=`, PHP rejects it as a duplicate after the loader already registered the SSI one — and vice versa (if the non-SSI extension loads first, the SSI loader detects the name already in `module_registry` and unregisters its own injected copy instead; see `loader/dd_library_loader.c`).

### Tag reference

The following tags may be present alongside the event. Use them to attribute the
crash and understand context:

| Tag | Example | Source | Description |
|-----|---------|--------|-------------|
| `profiler_inactive` | `0` | `counters.rs` | 1 = profiler was idle at crash time; 0 = it was not. |
| `profiler_collecting_sample` | `0` | `counters.rs` | Nonzero = profiler was collecting a sample at crash time. |
| `profiler_unwinding` | `0` | `counters.rs` | Nonzero = profiler was unwinding the stack at crash time. |
| `profiler_serializing` | `0` | `counters.rs` | Nonzero = profiler was serializing data at crash time. |
| `si_signo` | `11` | `sig_info.rs` | Raw signal number (`11` = `SIGSEGV`). |
| `si_signo_human_readable` | `sigsegv` | `sig_info.rs` | Signal name (`SIGSEGV`, `SIGBUS`, `SIGILL`, `SIGFPE`, …). Older versions may be lowercase. |
| `si_code` | `1` | `sig_info.rs` | Raw signal code; meaning is signal-dependent. |
| `si_code_human_readable` | `segv_maperr` | `sig_info.rs` | Signal code name (`SEGV_MAPERR`, `SEGV_ACCERR`, `BUS_ADRALN`, `ILL_ILLOPC`, …). |
| `si_addr` | `0x00007ff894af86c8` | `sig_info.rs` | Fault address from `siginfo_t.si_addr`. |
| `is_crash` | `true` | `errors_intake.rs` / `sidecar.c` | Always `true` for crash reports. |
| `incomplete` | `false` | `errors_intake.rs` | `true` = stack trace is truncated / could not fully unwind. |
| `data_schema_version` | `1.4` | `errors_intake.rs` | JSON schema version; current is `1.5`. |
| `uuid` | `2f530826-…` | `errors_intake.rs` | RFC 4122 UUID shared between crash ping and crash report. |
| `version` | `1.16.0` | `sidecar.c` | Service version from `DD_VERSION` or the active APM span. |
| `source` | `php` | `sidecar.c` | Language/runtime identifier (`"php"`). |
| `team` | `telemetry-and-analytics` | Datadog backend | Internal routing tag injected by the intake pipeline. Not from PHP code. |
| `instrumented_service` | `web.request` | Datadog Agent/backend | Resource/span type at crash time. Not from PHP code. |
| `datacenter` | `us1.prod.dog` | Datadog backend | Intake datacenter/region tag. Not from PHP code. |
| `datadog.submission_auth` | `api_key` | Datadog intake | Auth method used for submission. |
| `datadog.api_key_uuid` | `7cacaf92-…` | Datadog intake | UUID of the Datadog API key used. |

Check whether any profiler counter (`profiler_collecting_sample`,
`profiler_unwinding`, `profiler_serializing`) is nonzero — this attributes the
crash to profiler activity.

Print the triage summary before continuing.

## Phase 2 — Stacktrace correlation

Checkout the matching version tag in a worktree (tags are like `1.16.0`).
For PHP source, use the `php-src` repository next to this checkout; PHP tags
are like `PHP-8.1.33`.

> **Note:** Ondřej Surý packages for Debian may be slightly modified relative to
> upstream PHP. If discrepancies appear, use `apt-get source` inside an
> appropriate Docker container to obtain the exact source.

**If the native stacktrace is null or empty**, the crashtracker could not unwind
the stack (often because the stack itself was corrupted). In this case:
1. Check whether RIP falls in any mapped region using
   `.claude/find_map_region.py <rip> <event.json>`. If it does not, the
   instruction pointer was corrupted — a bad return address or function pointer.
2. Scan the other registers (RAX, RBX, RCX, RDX, RSI, RDI, R8–R15) for
   addresses that *do* fall inside Datadog binary regions; these may point to
   the data or code the process was operating on just before the crash.

Map each native stacktrace frame to source code:
1. Use `.claude/find_map_region.py <address> <event.json>` to identify which
   binary each instruction pointer belongs to. In schema 1.6+ events, frames
   include a `module_base_address` field that already identifies the binary —
   cross-check against the mapped files to confirm the binary name.
2. For frames in Datadog binaries, correlate to source using the worktree.
3. For frames in PHP, correlate to `php-src` at the matching tag.

To determine whether a frame in a PHP process belongs to a Datadog product:
first check the function name (it may clearly identify a DD module); if
inconclusive, check whether the instruction pointer falls within the mapped
executable segments of `ddtrace.so`, `ddappsec.so`, or
`datadog-profiling.so`.

If there are no Datadog product stack frames, still check whether a Datadog
product could be the root cause — for example, by having modified the behavior
of PHP functions that are clearly on the crashing call path. If this seems
unlikely, **ask the user before continuing with deep analysis**.

If frames land in unknown binaries, note them but focus on Datadog frames first.

**If you can identify the root cause at this point, stop and report.** Only
continue to Phase 3/4 if the analysis is ambiguous or low-confidence.

## Phase 3 — Binary verification (if needed)

If the stacktrace correlation is ambiguous or the crash is in Datadog code:

### Datadog binaries

1. Download the release binaries.

   **Standard install** (fetches from GitHub releases):
   ```
   .claude/dd_php_release_url '<version>' '<php_minor>' '<arch>' '<gnu|musl>' '<zts|nts>'
   ```
   Binaries are at:
   - `<tmpdir>/dd-library-php/trace/ext/<api_version>/ddtrace[-zts].so`
   - `<tmpdir>/dd-library-php/profiling/ext/<api_version>/datadog-profiling[-zts].so`
   - `<tmpdir>/dd-library-php/appsec/ext/<api_version>/ddappsec[-zts].so`
   - `<tmpdir>/dd-library-php/appsec/lib/libddappsec-helper.so`
   - `<tmpdir>/dd-library-php/appsec/lib/libddappsec-helper-rust.so`

   The `-zts` suffix is present when `zts` was passed as the last argument.
   If ZTS/NTS is ambiguous, run twice and compare symbol addresses against the
   crash IPs to identify which build matches.

   **SSI install** (`libddtrace_php.so` in maps) — fetches from
   `install.datadoghq.com`, no credentials needed:
   ```
   .claude/dd_php_release_url --ssi '<version>' '<arch>'
   ```
   One image contains both gnu/musl and NTS/ZTS for all PHP versions. Binaries
   are at:
   - `<tmpdir>/linux-<gnu|musl>/trace/ext/<api_version>/ddtrace[-zts].so`
   - `<tmpdir>/linux-<gnu|musl>/profiling/ext/<api_version>/datadog-profiling[-zts].so`
   - `<tmpdir>/linux-<gnu|musl>/appsec/ext/<api_version>/ddappsec[-zts].so`
   - `<tmpdir>/appsec/lib/libddappsec-helper.so`
   - `<tmpdir>/appsec/lib/libddappsec-helper-rust.so`

   Use the libc, PHP API version (e.g. PHP 8.3 → `20230831`), and ZTS/NTS from
   Phase 1 to select the right path.

   Use the version exactly as it appears in `metadata.library_version`.

2. Verify the binary matches the crash by comparing:
   - Size of first mapped region (from `/proc/self/maps`) vs. `p_memsz` of the
     first PT_LOAD segment (`readelf -l <binary>`). The mapped region spans
     from the page floor of `p_vaddr` to the page ceiling of
     `p_vaddr + p_memsz`, so the mapped size is the segment's virtual address
     range rounded **outward** to page boundaries; mapped size ≥ `p_memsz`,
     they need not be equal.
   - Note the base address: `map_start - (p_vaddr & ~(PAGE-1))`, where `PAGE`
     is typically `0x1000` on x86-64 (aarch64 can be 0x1000, 0x4000, or
     0x10000). In schema 1.6+ events, `module_base_address` in each frame is
     the load bias and can be used directly instead of computing it.

3. Disassemble around the crashing instruction pointer.

   > **GDB architecture must be set explicitly.** Without an inferior, GDB
   > defaults to `i386` (32-bit), which truncates 64-bit addresses in both
   > display and address operations. Always set the architecture first:
   > `set architecture i386:x86-64` (or `aarch64`). Without this, `add-symbol-file
   > -o` silently truncates the bias and disassembly targets the wrong address.

   The GDB pattern for disassembly without an inferior is: load the binary
   with `file` (provides memory access from ELF sections) **and** with
   `add-symbol-file -o <base_addr>` (attaches biased symbol names). Together
   they allow disassembling at actual crash IPs with correct symbol annotations:
   ```
   gdb -batch -ex 'set architecture i386:x86-64' \
       -ex 'file <binary>' \
       -ex 'add-symbol-file <binary> -o <base_addr>' \
       -ex 'disassemble <ip_addr>-32,<ip_addr>+32'
   ```

   SSI binaries from `install.datadoghq.com` are stripped — `file` gives memory
   access and `add-symbol-file -o` resolves exported symbols. For full symbols
   (line numbers, inlined functions), try the S3 tarball — it includes co-located
   `.debug` files but requires a manual CI job to have been triggered. Check with:
   ```
   curl -sI https://dd-trace-php-builds.s3.amazonaws.com/<version>/dd-library-php-ssi-<version>-<arch>-linux.tar.gz
   ```
   If available, use the `.debug` file for `add-symbol-file` instead of the `.so`:
   ```
   gdb -batch -ex 'set architecture i386:x86-64' \
       -ex 'file <binary>' \
       -ex 'add-symbol-file <binary>.debug -o <base_addr>' \
       -ex 'disassemble <ip_addr>-32,<ip_addr>+32'
   ```

   For monolithic builds (non-SSI), the binary is unstripped; the same pattern applies:
   ```
   gdb -batch -ex 'set architecture i386:x86-64' \
       -ex 'file <binary>' \
       -ex 'add-symbol-file <binary> -o <base_addr>' \
       -ex 'disassemble <ip_addr>-32,<ip_addr>+32'
   ```
   On macOS, run gdb/readelf/objdump inside a Docker container with
   `--platform=linux/amd64`. Build a Dockerfile with the tools you need
   rather than installing packages each time.

### PHP binaries (when PHP frames need deeper analysis)

If source code alone is insufficient to understand PHP frames:

1. Identify and download the PHP binaries. Common sources:
   - Ondřej Surý packages (Debian/Ubuntu)
   - PHP Docker images (Alpine or Debian, binaries installed under `/usr/local`)

2. Extract the binaries into the same temp directory used for Datadog binaries.

3. Verify each PHP binary matches the crash the same way as Datadog binaries:
   compare the size of the first mapped region against `p_memsz` of the first
   PT_LOAD segment (the mapped region spans the segment's virtual address range
   rounded outward to page boundaries, so mapped size ≥ `p_memsz`), note the
   base address.

4. Disassemble PHP frames using the same GDB pattern: `set architecture
   i386:x86-64` (or `aarch64`), then `file <binary>` for memory access, then
   `add-symbol-file <binary> -o <base_addr>` for biased symbol names.

## Phase 4 — Root cause

Based on the disassembly, register state, and source code:
- Identify what memory access or operation triggered the fault.
- Trace backward to find the bug (NULL deref, use-after-free, bad offset, etc.).
- Check if the fault address (`si_addr`) and the register state are consistent
  with the hypothesis.

## Output

Present findings as:

```
### Crash Summary
- **Signal**: ...
- **Version**: ... | PHP ... | arch ... | libc ...
- **Products**: ddtrace / ddappsec / profiling
- **Profiler active**: yes/no

### Root Cause
<explanation with source file:line references>

### Evidence
<key register values, disassembly snippet, source correlation>

### Confidence
<high / medium / low — and what would raise it>
```

## Rules

- If no Datadog frames are present, check for indirect causation (DD hooks on
  the crashing path) before deciding the crash is unrelated. If it still seems
  unrelated, ask the user whether to continue before doing deep analysis.
- Use `git worktree` (not `git checkout`) to inspect version-specific source.
- On macOS, use Docker with `--platform=linux/amd64` for ELF tools. A
  pre-built image with all required tools (`gdb`, `binutils`, `python3`, `uv`)
  is defined in `.claude/skills/crash-analysis/Dockerfile.tools`. Build it once
  with:
  ```
  docker build --platform=linux/amd64 -t dd-trace-php-crash-tools \
    .claude/skills/crash-analysis/ \
    -f .claude/skills/crash-analysis/Dockerfile.tools
  ```
  Then use `docker run --rm --platform=linux/amd64 -v <tmpdir>:/bins dd-trace-php-crash-tools <cmd>`.
- Parallelize extractions and lookups wherever possible.
