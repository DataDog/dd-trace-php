Override for `reviewers/conventions.md` (in the core skill folder) — read that file first, then this.

# Conventions — dd-trace-php specifics

This file starts with one confirmed pattern and should grow. Do not treat it as exhaustive.

The source of truth for *how* style is checked is [`phpcs.xml`](../../../phpcs.xml) via `composer lint` / `composer fix-lint`. [`CONTRIBUTING.md`](../../../CONTRIBUTING.md) § "PHP linting" still names PSR-2; the ruleset that command actually runs is [PSR-12](https://www.php-fig.org/psr/psr-12/). When those disagree, `phpcs.xml` wins.

## PHP userland follows the phpcs ruleset (PSR-12)

A new or edited `.php` file that fails `composer lint` is a finding (Allman braces and tab indent fail that check). Do not invent a different house style than `phpcs.xml`.

CI does **not** run `composer lint`. It runs `composer ci-lint`, a separate nearly-empty gate (same CONTRIBUTING section). That is not "this repo has no PHP style standard" — the standard is still `composer lint` / `phpcs.xml`.
