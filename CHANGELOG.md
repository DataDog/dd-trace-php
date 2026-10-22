Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Fixed
- Use the alternate signal stack for the remote config SIGVTALRM handler #4355 (thank you @mstegmeyer!)
- JSON serialization trips the soft-OOM safeguards and completely fails in that case #4362

## Profiling
### Fixed
- Fix profiler pthread cleanup after FrankenPHP unload #4360
