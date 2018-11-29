# Changelog

This short guide should give basic description how `CHANGELOG.md` is organized

All notable changes to this project will be documented in `CHANGELOG.md` file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Changelog entry format

Changelog entry should try to form a coherent sentence with the heading e.g:

```md
### Added
- integration
```

Changelog entry must link to relevant PR(s) via ```#reference``` e.g. ```new integration #124, #122```

Changelog entry might mention PR author(s) via ```@mention```- especially when he/she was not a member of DataDog team.

Changelog entry should start with lowercase or preferably, a specific integration name it concerns e.g. Laravel.

## Example Changelog

```md
## [Unreleased]
### Added
- Laravel integration #124 (@pr_author)
### Fixed
- Laravel integration breaking bug #123
### Changed
- Laravel integration documentation #111
### Removed
- support for PHP 5.3 #2

## [0.0.1] - 2018-01-01
### Added
- support for PHP 5.3 #1

[Unreleased]: https://github.com/DataDog/dd-trace-php/compare/0.0.1...HEAD
[0.0.1]: https://github.com/DataDog/dd-trace-php/compare/0.0.0...0.0.1
```
