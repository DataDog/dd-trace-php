Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## Tracer
### Fixed
- Fix closed resource handling in live debugger #3437
- Ensure local variables in exception replay are redacted #3440
- Reset ddtrace_endpoint properly #3451
- Use a local limiter if shared memory fails to allocate #3454
- Do not skip error handling for timeouts happening during hook execution #3459
- Defer Theme\Registry::getRuntime() call until posthook #3465
- Ensure there's no trailing semicolon with only tid as propagated tag #3466

## Profiling
### Fixed
- Reset interrupt count when removing interrupt #3455

## Application Security Management
### Fixed
- Ensure json dependency is loaded at runtime #3462
- Fix several bugs and potential bugs in appsec #3463
- When helper is unavailable, avoid very long waits (> 7s) #3464
