Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products

### Fixed
- Fix SSI crashing on apache reload; add SSI int tests for appsec #3724, #3733
- Fix entity_id handling for Podman cgroupns=host cgroup path DataDog/libdatadog#1828

### Internal
- Changed defaults of configurations and fixed DD_TRACE_HTTP_CLIENT_ERROR_STATUSES #3621, #3677

## Tracer
### Fixed
- Fix _dd.p.ksr scientific notation for very small sampling rates #3721
- Fixed shell_exec() null return being interpreted as error #3723
- Batch endpoint collection & remove Wordpress Endpoint collection #3764
- Fix sidecar performance by batching ack sending & consumption DataDog/libdatadog#1835

## Profiler
### Fixed
- Fix crash due to AAS getenv #3746

### Internal
- Update libdatadog to v30.0, make CA root optional for profiling #3758
