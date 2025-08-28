Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Internal
- Add injection metadata fields to telemetry forwarder #3359

## Tracer
### Added
- Add http.route tag to SymfonyIntegration.php #2992
- Add setting to avoid obfuscating mongodb queries #3390
- Handle native HTTP requests #3366

### Changed
- Expose curl_multi_exec_get_request_spans() as non-internal #3389
- Use resources_weak_* API for Curl as well #3386
- Gracefully handle sidecar broken pipes #3370
- Enable log injection by default #3355

### Fixed
- Capture the stack for log probes #3367 
- Properly cache the telemetry cache #3387
- Fix names of global git tags for debugger #3377
- Fix SQLSRVIntegration resource handling #3379
- Set DD_APPSEC_RASP_ENABLED default to true as on the tracer #3374
- Fix top Code Origin frame for ExecIntegration and KafkaIntegration #3392

### Internal
- Update baggage telemetry typo #3382
- Switch to bookworm containers #3375

## Application Security Management
### Added
- Add fingerprint capabilities #3371
- Implement jwt #3352

### Fixed
- Fix musl appsec helper shutdown crash #3378

### Internal
- Fix submission of telemetry logs from appsec #3373
