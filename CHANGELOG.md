Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Added
- Add injection information to phpinfo output for the SSI loader #3271
- Allow specifying datadog.loader.force_inject=1 in loader #3278

### Fixed
- Add missing dep to injected ddappsec #3252
- Filter SSI paths from installer ini paths #3275

## Tracer
### Added
- Add http status error configuration #3223 (Thanks @scott-shields-github)
- Baggage span tags #3262

### Changed
- Avoid retrieving all the roots all the time in remote config DataDog/libdatadog#1069

### Fixed
- Fix Laravel error reporting #3185
- Fix crash with non-interned string in Trace attribute tags #3251
- Init ddtrace_coms_globals.tmp_stack #3256 (Thanks @junjihashimoto)
- Enhance Guzzle integration to handle promise fulfillment state #3260
- Block signals for mysqli_real_connect too #3264
- Fix exception serialize arena cleanup #3272
- Handle stack-allocated execute_data but outside of stack allocated func #3273
- Fix WordPress integration hook handling for "static" and object methods #3274

### Internal
- Remove non actionnable telemetry logs #3270

## Profiling
### Changed
- Re-enable allocation profiling with JIT for PHP 8.4.7 #3277

### Fixed
- Fix borrow error in request shutdown #3247
- Fix crash in ZEND_INIT_ARRAY #3255

### Internal changes
- Add opcache tags in crash report #3231
- Use local_key_cell_methods #3248

## Application Security Management
### Fixed
- Use the ddtrace handle instead of dlopen(NULL) #3244, #3249
