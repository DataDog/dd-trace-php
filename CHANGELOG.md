Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Added
- Support PHP 8.6 #4273

### Fixed
- Make the sidecar more robust: gracefully handle shared memory errors such as ENOSPC instead of stopping, move messages larger than the socket buffer limit to shared memory, receive the last messages of clients exiting right away, and keep the watchdog working during core dumps DataDog/libdatadog#2408, DataDog/libdatadog#2720, DataDog/libdatadog#2717, DataDog/libdatadog#2740

## Tracer
### Added
- Support line probes in Dynamic Instrumentation, building on the new `DDTrace\install_line_hook()` API to hook source lines and line ranges #4275, #4411
- Add Data Streams Monitoring for Kafka producers and consumers, enabled with `DD_DATA_STREAMS_ENABLED=true` #4380
- Support `DD_TRACE_STATS_ADDITIONAL_TAGS` to add tag dimensions to client-side stats #4404
- Report feature flag evaluation counts, configurable with `DD_FLAGGING_EVALUATION_COUNTS_ENABLED` #4288
- Log Feature Flags diagnostics with `DD_TRACE_DEBUG=1` #4327

### Fixed
- Always unblock SIGVTALRM, including at request start on Apache ZTS, so remote config notifications are delivered #4388
- Fix thread-mode sidecar reconnect issues and remote config notifications by binding sidecar state to its connection #4299
- Fix heap corruption on the request after a failed request startup, caused by stale telemetry service/env names #4414
- Retry socket connections and more stream operations interrupted by ddtrace's signals; before PHP 8.2.27, 8.3.15 and 8.4.2, `php_network_connect_socket()` failed on EINTR #4375
- Stop the OpenTelemetry integration from overriding sampling unless a custom sampler is set, and stop attaching spans to the OTel context storage, which left orphaned spans and leaked memory in worker mode #4419 (thank you @Ardenexal for the report!)
- Make the hook sandbox state thread-local, fixing ZTS races (e.g. FrankenPHP) where warnings inside hooks were thrown as exceptions and warnings elsewhere were swallowed #4372 (thank you @Ardenexal for the report!)
- Exclude undefined variables from Exception Replay's `$locals`, which broke serialization of captured exceptions such as in the Symfony profiler #4389 (thank you @jaugustin for the report!)
- Only use peer tags on client, producer and consumer spans and `_dd.base_service` on internal spans for client-side stats aggregation, matching the agent DataDog/libdatadog#2554

## AppSec
### Changed
- Isolate the request body parsers' allocations, count them against `memory_limit` and recover from out-of-memory conditions; parsed bodies are now sent to the helper as MessagePack directly #4361

### Fixed
- Keep every trace that has an AppSec event when one PHP request serves many HTTP requests (FrankenPHP worker mode, RoadRunner) #4405
- Fix a memory leak in the AppSec helper when the active service changes #4390
