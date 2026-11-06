Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Move ddtrace-appsec-body to the root workspace, fixing cargo vendoring #4437

## Tracer
### Changed
- Eliminate reverse function hash table collision overhead #4443
- Optimize method visitation on class load from opcache away #4447 (thank you for the report @Teko012)

### Fixed
- Back up jit_trace_num in our custom autoloader #4446 (thank you for the report @Ardenexal)
- Fix size truncation display in debugger #2810

### Internal changes
- Preserve VM registers around nested JIT declare handlers #4438
