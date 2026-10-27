---
name: php-lint
description: >-
  Add a PHPCS sniff or custom script to the blocking PHP lint CI job.
  Use when the user asks to add a lint rule, enable a commented sniff,
  write a tooling/php-lint custom check, or grow composer ci-lint.
allowed-tools: Bash Read Grep Glob Edit
---

# Add a PHP lint rule

Grow the blocking `PHP lint` job one check at a time. Do not turn this
into a formatter or PSR-12 gate.

Background: [tooling/php-lint/README.md](../../../tooling/php-lint/README.md).

## Decide PHPCS vs custom script

Use a **PHPCS sniff** when the check is a stock sniff (file shape, tokens,
forbidden constructs). Edit `tooling/php-lint/phpcs.xml`.

Use a **custom script** when the invariant is repo-specific (forbidden
APIs, generated-file drift, naming). Add
`tooling/php-lint/scripts/<name>.php` or `<name>.sh`.

Do not add both for the same check.

## Hard constraints

- Scope is first-party `src/` only. Generated bridge files
  (`src/bridge/_generated_*.php`) and `tests/Frameworks/` stay out.
- The job runs once on current NTS PHP. It does not compile the
  extension and is not matrixed across PHP 7.0-8.5.
- Checks must be version-agnostic. Do not parse PHP with the runner's
  engine unless the result is valid for every supported PHP.
- Never enable `<rule ref="PSR12"/>` wholesale.
- Do not enable `Generic.PHP.Syntax` (`php -l`) or
  `Generic.PHP.DeprecatedFunctions` unless the user explicitly accepts
  the version / tool-upgrade risk. Those stay commented in `phpcs.xml`.
- Do not add `PHP lint` to `.gitlab/flaky-jobs.txt` or set
  `allow_failure`. The job is meant to block merge-gate.

## Workflow — PHPCS sniff

1. Read `tooling/php-lint/phpcs.xml`. Prefer uncommenting a sniff that
   is already documented there over adding a new one.
2. Add **one** sniff, for example:

   ```xml
   <rule ref="Generic.Files.EndFileNewline"/>
   ```

3. Run `composer ci-lint` (or `bash tooling/php-lint/run.sh`) from the
   repo root. Use PHP 7.4+ and Composer; the extension is not required.
4. If it fails:
   - Mechanical cleanup (newline, tabs, trailing space) is OK in the
     same change. Prefer `tooling/php-lint/vendor/bin/phpcbf
     --standard=tooling/php-lint/phpcs.xml` when the sniff is
     auto-fixable.
   - If a **generated** file fails, fix the generator so regeneration
     still passes. Do not leave a manual edit that the next generate
     will wipe. Example: `tooling/stubs/generate-stubs.php` must append
     a trailing newline to `src/ddtrace_php_api.stubs.php`.
   - If the cleanup is large or opinionated, comment the sniff back out
     (with the failing files named) and stop. Ask before a repo-wide
     reformat.
5. Keep the sniff only if `composer ci-lint` exits 0.
6. Update the enabled-sniff list in `tooling/php-lint/README.md` if you
   changed what is on.

## Workflow — custom script

1. Add `tooling/php-lint/scripts/<name>.php` or `<name>.sh`.
   - `*.php` is run with `php`
   - `*.sh` is run with `bash`
   - `README.md` and `.gitkeep` are ignored
2. CWD is the repo root. Exit 0 to pass, non-zero to fail.
3. Print the failing paths and why. Do not depend on the `+x` bit;
   `run.sh` dispatches by extension.
4. Keep the script small. Do not scan `tests/Frameworks/` or
   `vendor/`.
5. Run `composer ci-lint`. The runner continues after a failure so
   PHPCS output is still visible.

## Local commands

```bash
composer ci-lint
# or
bash tooling/php-lint/run.sh
```

PHPCS lives in `tooling/php-lint/vendor/` (isolated Composer install).
`run.sh` runs `composer install` there on first use.
