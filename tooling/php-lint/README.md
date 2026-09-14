# PHP lint CI job

A blocking but nearly-empty quality gate for PHP userland. It exists so
we can add checks later without standing up CI, Composer, or a ruleset
from scratch each time.

Run locally:

```bash
composer ci-lint
# or
bash tooling/php-lint/run.sh
```

## Why this job

This repo supports many historical PHP versions (7.0 through current).
A full formatter or PSR-12 gate would fight that: existing `src/` has
drifted, `composer lint` is not run in CI, and version-specific syntax
must stay legal. Enforcing a style guide on day one would create merge
pain without catching bugs.

This job is the other extreme: a reliable PR hook that starts with
almost no opinions. New rules are opt-in and added one at a time, only
after the tree already complies (or after a scoped cleanup). That keeps
the cost of each rule visible and reviewable.

It is not a mandate to reformat the tree. It is a place to put "this
must never land" checks.

The linter runs once, on a current NTS PHP, as a text analysis of
`src/`. It does not compile the extension and is not matrixed across
PHP 7.0-8.5. Version-sensitive checks (for example `php -l` /
`Generic.PHP.Syntax`) stay off because they inherit the runner's
parser.

## What runs

`tooling/php-lint/run.sh` does two things and fails the job if either
fails:

1. **PHP_CodeSniffer** (`tooling/php-lint/phpcs.xml`), the usual PHP
   lint tool. Isolated install via `tooling/php-lint/composer.json` so
   root `require-dev` (PHPUnit, etc.) is not involved.
2. **Custom scripts** in `tooling/php-lint/scripts/`. Use these for
   repo-specific invariants that are not a PHPCS sniff (forbidden
   APIs, generated-file drift, naming, and so on).

Enabled sniffs are version-agnostic safety checks (merge-conflict
markers, BOM, LF line endings, final newline, no tabs, no trailing
whitespace, open/close tags, backticks, `goto`, `FIXME`, `eval`).
They are not a style guide.

`TODO` comments, `php -l`, and deprecated-function detection stay
commented in `phpcs.xml`. PSR-12 is off.

Scope is first-party `src/` only. Generated bridge files and
`tests/Frameworks/` are out of scope.

This is separate from `composer lint` / `composer fix-lint`, which
still use the root `phpcs.xml` PSR-12 ruleset for optional local
cleanup.

## Adding a PHPCS rule

Edit `tooling/php-lint/phpcs.xml`. Add one sniff, run
`bash tooling/php-lint/run.sh`, and only keep the sniff if current
`src/` already passes (or land the cleanup in the same change).

```xml
<rule ref="Generic.Files.EndFileNewline"/>
```

Do not enable `<rule ref="PSR12"/>` until the tree has been cleaned
up. Prefer single sniffs.

## Adding a custom script

Drop an executable check into `tooling/php-lint/scripts/`:

- `*.php` is run with `php`
- `*.sh` is run with `bash`
- any other executable file is run directly

CWD is the repo root. Exit 0 to pass, non-zero to fail the job.
`README.md` and `.gitkeep` are ignored. The runner continues after a
failure so one script does not hide the next.

Keep scripts small and version-agnostic. Do not parse PHP with the
runner's engine unless the check is valid for every supported PHP.
