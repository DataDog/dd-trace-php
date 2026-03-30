# Job-Group Documentation Format

Each file in `.claude/ci/` documents one group of CI jobs that share the
same runner type, image, and execution model.

## Section order

1. **H1 title** — short name for the group
2. **`## CI Jobs`** — job names + source files + runner/image/matrix
3. **`## What It Tests`** — what the jobs build and run
4. **Reproduction sections** — one `##` per logical test target, each
   with `### Full suite` → `### Single test` → named variants
5. **`## Gotchas`** — non-obvious facts that cause silent failure

Omit any section that adds no value (e.g. trivial prerequisites).
Submodule init is documented in `general.md` — do not repeat it here.

## `## CI Jobs` format

The **Source** line must come first — before the job table — so a reader
can jump straight to the definition. List every file that is materially
relevant to understanding or modifying the job (generator script, shell
scripts it calls, workflow file, action definitions, etc.). Then a table
with columns `CI Job`, `Image`, `What it does`:

```
## CI Jobs

**Source:**
- `.gitlab/generate-appsec.php` — generates the appsec-trigger child
  pipeline; defines the job matrix and `script:` sections inline
- `appsec/scripts/compile_extension.sh` — build script called by the job

| CI Job | Image | What it does |
|--------|-------|--------------|
| `test appsec extension: [{ver}, {arch}, debug]` | `dd-trace-ci:php-{ver}_bookworm-6` | Builds extension + runs .phpt tests |
| `test appsec helper asan` | `dd-trace-ci:bookworm-6` | C++ helper ASAN gtest suite |
| `appsec code coverage` | `dd-trace-ci:php-8.3_bookworm-6` | (not needed locally) |

Runner: `arch:amd64` + `arch:arm64`
Matrix: PHP 7.0--8.5 × {debug, debug-zts, debug-zts-asan (7.4+)}
```

If there is only one source file, the single-line form is fine:

```
**Source:** `.github/workflows/prof_correctness.yml`
```

Use `{placeholder}` for matrix dimensions. Note any dimension that is
CI-only and has no effect on local commands (e.g. `{arch}` controls only
the runner tag).

## Reproduction section rules

- Show the full-suite command first; single-test second. Single-test is
  the most frequent operation when iterating on a fix.
- Note CI/local divergences inline (not in Gotchas). E.g.:
  - packages CI installs that are not needed locally
  - env vars CI passes unconditionally that are harmless to include
  - output paths that differ between CI and the local command
- Use `$(nproc)` not a hardcoded `-j4`.
- On macOS/Apple Silicon prefer `--platform linux/arm64`; use a separate
  cache name per arch.

## Gotchas rules

Each bullet must be a fact **not derivable from reading the commands**
that would cause silent failure or wasted time. Do not repeat anything
already stated inline in the reproduction commands.
