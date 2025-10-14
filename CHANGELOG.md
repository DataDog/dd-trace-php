Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Changed
- Reduce integrations overhead #3380
- Avoid unnecessary gc_collect_cycles if there's no open span #3428
- Make use of fast_shutdown to avoid freeing overhead #3429
- Optimize PDOIntegration::parseDsn() #3430

### Fixed
- Fix #3135: Force flushing on shutdown of entry point processes #3398
- Support curl_multi_exec root spans #3419
- Fix a couple memory leaks #3420

## Profiling
### Added
- Add source code integration #3418

### Fixed
- Fix missing line numbers #3417
- Early init default connector to fix env var race #3432

### Internal
- Refactor tag handling #3423
- Permanently enable compilation of allocation, exception, and timeline features #3431
