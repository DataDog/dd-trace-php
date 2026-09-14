Override for `reviewers/conventions.md` (in the core skill folder) — read that file first, then this.

# Conventions — dd-trace-php specifics

This file starts with one confirmed pattern and should grow. Do not treat it as exhaustive.

The source of truth is [`CONTRIBUTING.md`](../../../CONTRIBUTING.md) § "PHP linting". Apply that section as written.

## PHP userland follows PSR-2

PHP under this repo must follow [PSR-2](https://www.php-fig.org/psr/psr-2/). Style is checked with `composer lint` and auto-fixed with `composer fix-lint`. A new or edited `.php` file that uses Allman braces, tabs for indent, or otherwise fails that check is a finding. Do not invent a different house style.
