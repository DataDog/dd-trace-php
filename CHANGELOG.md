Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Properly reset SSI loader global state on shutdown to cleanly support reloading #3881

### Internal
- Spawn the sidecar via dynamic linker instead of trampoline #3869

## Tracer
### Added
- Add support for OpenTelemetry logs (`DD_LOGS_OTEL_ENABLED=true`, disabled by default) #3748

### Changed
- Crashtracking now collects stack traces from all threads at the moment of a crash #3866

### Fixed
- Fix NULL dereference crash in ZTS mode during sidecar/telemetry shutdown #3886
- Ensure remote config processing happens strictly after request initialization #3882
- Strip libpq-style paired quotes from PostgreSQL `dbname` DSN value in PDO integration #3885
- Fix use-after-realloc crash in tracestate formatting #3874

## Profiling
### Fixed
- Prevent panics in profiling encoding under out-of-memory and out-of-bounds conditions #3888

## AppSec
### Added
- Add AppSec integrations to Laminas Framework (http.route, endpoint collection, login events) #3716

### Changed
- Update recommended ruleset to v1.18.0, adding Stripe and LLM endpoint detection rules #3859

### Fixed
- Treat cleared shared memory as no-config rather than an error in AppSec helper #3876
- Avoid the possibility of sensitive data going to the telemetry logs backend via WAF strings #3884

### Internal
- Fix `blocked_request` metric tag detection in AppSec helper #3863
- Add `block` tag to `rasp.rule.match` metric #3870
- RFC-1012 metrics improvements: WAF duration distributions, rule_variant tag, and tag fixes #3850
