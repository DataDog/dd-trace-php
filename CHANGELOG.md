Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Call exit handlers when FrankenPHP shuts down #4350
- Use fallible allocations when serializing traces and parsing request bodies #4345, #4351
- Avoid null pointer arithmetic in CLI script basename detection #4320

## Tracer
### Fixed
- Hook `#[DDTrace\Trace]` attributes on preloaded functions #4324
- Propagate `DD_ENTITY_ID` to DogStatsD metrics sent by the extension #4328 (thank you @lcobucci!)

## Profiling
### Fixed
- Fix thread safety issues in profiler globals initialization and shutdown, and avoid a 100ms sleep at shutdown #4296, #4301

## AppSec
### Fixed
- Avoid accessing freed logging state at shutdown #4350
