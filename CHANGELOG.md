Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Internal
- Update and shrink build images, migrate to clang 19 #3771

## Tracer
### Added
- Support ApmTracingMulticonfig in dynamic config #3773

### Fixed
- Improve Symfony http.route resolution performance #3779 (thank you @B-Galati for the report!)
- Wrap PDO::__construct for signal handling #3786

### Internal
- Fix spawn\_worker trampoline issues DataDog/libdatadog#1844

## AppSec
### Added
- Enable rust helper on PHP 8.5 #3780 (can be disabled with `DD_APPSEC_HELPER_RUST_REDIRECTION=false`)
