Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Changed
- Update PHP 8.6 ABI to 20261112 #4490
  - This allows dd-trace-php to run with PHP 8.6.0RC7 and newer. Older PHP 8.6 RCs require dd-trace-php 1.27.1.

### Internal changes
- Fix fpm apk tar headers for apk-tools 2.14.13 / 3.0.9 #4454

## Tracer
### Changed
- Disambiguate line hooks with multiple eligible functions #4474

### Fixed
- Don't capture exception replay locals from frames without initialized CVs #4450
- Fully drop empty traces and keep error traces #4497

### Internal changes
- Build the PECL tarball offline from vendored crates #4493
