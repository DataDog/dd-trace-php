# Changelog

All notable changes to this project will be documented in this file - [read more](docs/changelog.md).

## [Unreleased]

## [0.30.1]
### Fixed
- releasing This object as soon as it stops being used #536

## [0.30.0]

### Fixed
- Shutdown span flushing blocking the process when forked #493
- Memory access errors in cases when PHP code was run after extension data was freed on request shutdown #505
- Request init hook working when open_basedir restriction is in effect #505
- Ensure global resources are freed in shutdown #521 #523
- Http transport not setting required `X-Datadog-Trace-Count` header #525

### Changed
- Remove `zend_execute_ex` override and trace `ZEND_DO_UCALL` #519

## [0.29.0]

### Fixed
- Edge case where the extension version and userland version can get out of sync #488

### Changed
- Prefix hostnames as service names with `host-` to ensure compatibility with the Agent #490

## [0.28.1]

### Fixed
- Race condition when reading configuration from within writer thread context #486

## [0.28.0]

### Added
- Officially support Symfony 3.0 and 4.0 #475

### Fixed
- Stack level too deep error due to changes in how PHP interprets Opcodes caused by the extension #477

### Changed
- Backtrace handler will be run only once and will display information about maximum stack size being reached #478

## [0.27.2]

### Changed
- Valgrind configuration to perform more thorough memory consistency verification #472

### Fixed
- Memory leak detected in tests #472

## [0.27.1]

### Fixed
- Memory leak when garbage collecting span stacks #469

## [0.27.0]

### Added
- Set the hostname of a URL as the service name for curl and Guzzle requests when `DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true` #459

### Changed
- Replace multiple calls to `mt_rand()` (32-bit) with one call to `dd_trace_generate_id()` which implements [MT19937-64](http://www.math.sci.hiroshima-u.ac.jp/~m-mat/MT/VERSIONS/C-LANG/mt19937-64.c) and returns a 63-bit unsigned integer as a string #449

### Fixed
- Traces no longer affect deterministic random from `mt_rand()` #449
- Fix API change with Symfony 4.x EventDispatcher #466

## [0.26.0]

### Added
- Initial implementation of flushing spans via background thread #450

### Changed
- URL-normalization rule boundaries #457

## [0.25.0]

### Added
- Support for Slim Framework v3 #446
- IPC based configurable Circuit breaker `DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC` and `DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES` used when communicating with the agent #440
- Normalized URL's as resource names; a CSV string of URL-to-resource-name mapping rules with `*` and `$*` wildcards can be set from `DD_TRACE_RESOURCE_URI_MAPPING`. This feature is disabled by default to reduce cardinality in resource names; to enable this feature set `DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=true` #442

## [0.24.0]

### Added
- Tracer limited mode, stopping span creation when memory use raises to 80% of current PHP memory limit #437
- Configurable Curl timeouts `DD_TRACE_AGENT_TIMEOUT` and `DD_TRACE_AGENT_CONNECT_TIMEOUT` when communicating with the agent #150
- Configurable `DD_TRACE_REPORT_HOSTNAME` reporting of hostname via root span #441
- Support for CakePHP v2 and Cake Console v2 #436

### Fixed
- Generation of `E_WARNING` in certain contexts of PHP 5 installs when the `date.timezone` INI setting is not set #435

## [0.23.0]

**NOTE: We changed the way the service name can be configured. Now you must use `DD_SERVICE_NAME` instead of `DD_TRACE_APP_NAME` for consistency with other tracers. Usage of `DD_TRACE_APP_NAME` is now deprecated and will be removed in a future release.**

### Added
- Support for [Lumen](https://lumen.laravel.com/) 5.2+ #416
- Tracing support from the CLI SAPI #422
- Support for Laravel Artisan #422

### Changed
- Now the way to configure service name is through `DD_SERVICE_NAME` instead of `DD_TRACE_APP_NAME` #432

## [0.22.0]

### Added
- Official support for PHP 7.3 #429
- Tracer limited mode where spans are not created to preserve resources #417

### Fixed
- Error when a subclassed integration returns an object that cannot be cast as a string #423

## [0.21.0]

### Added
- `dd_trace_forward_call()` to forward the original call from within a tracing closure #284

### Fixed
- `parent::` keyword not honored from a subclass when forwarding a call from a tracing closure #284
- Private and protected callable strings not resolved properly from a tracing closure #303

## [0.20.0]

### Added
- Force tracing or discarding trace via special Span tag (manual.keep and manual.drop) #409

### Fixed
- Resource use by caching configuration values instead of processing data on every access #406

## [0.19.1]

### Fixed
- Tracing of functions called using DO_FCALL_BY_NAME opcode #404
- Curl headers not being correctly set #404

## [0.19.0]

### Changed
- Span and SpanContext main implementation uses public fields to share data to allow faster serialization and overall Span overhead #398
- `DDTrace\Encoders\SpanEncoder::encode()` now takes an instance of `DDTrace\Data\Span` instead of `DDTrace\Contracts\Span` #398
- `DDTrace\Processing\TraceAnalyticsProcessor::process()` now takes an instance of `DDTrace\Data\Span` instead of `DDTrace\Contracts\Span` #398
- Improve performance of setTag and setResource #398
- Load required PHP files in one go #387
- Load optional PHP files without filesystem check #387

## [0.18.0]

**NOTE: THIS IS A BREAKING CHANGE RELEASE**

This change should not impact most users.

### Added
- MessagePack serialization for traces sent to the Agent with a new function `dd_trace_serialize_msgpack()` #378

### Changed
- Request init hook module blacklist now avoids miss matching partial matches #372
- Add 10MB cap to payloads sent to the Agent #388
- Added an `getTracesAsArray()` method to `DDTrace\Contracts\Tracer` which returns an array of spans (which are also encoded as an array.) To encode an instance of `DDTrace\Contracts\Span` as an array, use `DDTrace\Encoders\SpanEncoder::encode($span)` #378
- `DDTrace\Transport::send()` now takes an instance of `DDTrace\Contracts\Tracer` instead of an `array` #378
- `DDTrace\Encoder::encodeTraces()` now takes an instance of `DDTrace\Contracts\Tracer` instead of an `array` #378
- The default encoder is now `DDTrace\Encoders\MessagePack`. You can still use the JSON encoder by setting the environment variable `DD_TRACE_ENCODER=json`. It is generally not recommended to use the JSON encoder as parsing JSON payloads at the Agent level is more CPU & memory intensive.

## [0.17.0]

### Added
- Integration aware spans #360
- Trace Analytics Client Configuration #367

## [0.16.1]

### Fixed
- Error traces don't appear in "Total Errors" panel #375

## [0.16.0]

### Changed
- When shutdown hook is executed we disable all tracing to avoid creating unnecessary spans #361
- Inside request init hook we disable all function tracing when we decide not to trace #361

### Added
- Disable request_init_hook functionality in presence of blacklisted modules via `ddtrace.internal_blacklisted_modules_list=some_module,some_other_module` #345 & #370
- Integration-level configuration #354
- `dd_trace_disable_in_request` function which disables all function tracing until request ends #361

### Fixed
- Symfony template rendering spans #359
- Laravel integration user ID errors #363
- Non-success HTTP response codes aren't properly categorized as errors in the APM UI #366

## [0.15.1]

### Added
- Symfony 2.3 web tests for resource name #349
- Update images and enable leak detection, split tests in CI to Unit, Integration and Web #299

### Fixed
- Resource name on Symfony 2.x requests served through controllers #341
- Sanitize url in web spans #344
- Laravel 5.8 compatibility #351

## [0.15.0]

### Changed
- Removed beta references and get ready for GA #339

## [0.14.2]

### Fixed
- Ensure Function name is safely copied to avoid freeing persistent string #333

## [0.14.1]

### Fixed
- Large number of mysqli spans not containing relevant information #330

## [0.14.0]

### Added
- Loading of integrations before knowing if the library will be actually used #319
- Ability to define tracing for not yet defined methods and classes #325

## [0.13.4]

Special thanks to @stayallive for helping us debugging the memory issues in his environment! His help and guidance were
of paramount importance.

### Fixed
- Accessing freed memory when instrumentation code un/instrumented itself #314
- Freeing `$this` object prematurely in PHP-FPM VM #317

## [0.13.3]

### Fixed
- 7.x handling of `$this` pointer passed to the closure causing errors in PHP VM #311

## [0.13.2]

### Added
- Optional extension .so files compiled with "-g" flag #306
- Log backtrace on segmentation fault, enabled via ddtrace.log_backtrace=1 #300

### Fixed
- Auto-instrumentation when user's autoloader throws exception on not found #305

## [0.13.1]

### Fixed
- Honor ddtrace provided by composer if user provided one #276
- Remove unused function that was moved to dispatch_table_dtor() #291
- PHP 5.4 incorrectly handling nested internal functions #295

## [0.13.0]

### Added
- Span::setResource as a legit method # 287
- Logging more span's info when in debug mode # 292

### Fixed
- Symfony 4.2 traces generation #280
- Memory leak and misshandling of return value in PHP 5.4 #281
- Drupal crashes (temporary workaround) #285
- Tracing of http status code in generic web requests #288
- Route not set in symfony 3.4 when user calls exit() #289
- Fix hash table dtor for PHP 7 #290

## [0.12.2]

### Fixed
- Zend integration incompatibility with Yii #282

## [0.12.1]

### Fixed
- Post-install to link the extension to all installed SAPI's for common configurations #277

## [0.12.0]

### Added
- Support for global tags via the environment variable `DD_TRACE_GLOBAL_TAGS=key1:value1,key2:value2` #273

### Fixed
- Memory leaks in `$this` object and return value handling in PHP 5.6 and 7.x #268
- Alpine APK package file being badly formatted when some file paths were over 100 character long #274
- Extension being compatible with CentOS 6 and other distributions using old Glibc #265

## [0.11.0]

**WARNING: THIS IS A BREAKING CHANGE RELEASE**

This change should not impact most of the users as starting from `0.10.0` it is not required (and not recommended) to
manually set the transport. `DDtrace\Transport\Http` no longer accepts a logger as the second argument as it uses
the globally registered logger. If you are using the `Http` class, just remove the second argument from the constructor
invocation.

### Added
- Support for guzzle 6 #254
- Configurable Sampler #260
- Debug mode for logging #261
- Basic tracing for unsupported and custom frameworks #264
- Support for symfony 3.3 #266 and #243
- Build php 5.4 extension locally #267

### Fixed
- Composer PHP compatibility declaration #247
- Release notes for PECL and fix type-os in CHANGELOG #248
- Add missing files to PECL releases #252
- PHP 5.4 installation and build #255
- Trigger of autoloader un-tracing did not respect object #256
- docker-compose based packages verification #257
- Incorrect tar command in one-liner example from getting_started.md #258 - thanks @danielkay
- Auto-instrumentation in Symfony 3.4 and PHP 5.6 #262
- Type-o in command to install .deb packages #263

## [0.10.0]

**WARNING: THIS IS A BREAKING CHANGE RELEASE**

Refer to the [Migration Guide](UPGRADE-0.10.md) for a detailed description.

At an high level here are the breaking changes we introduced:

- We removed OpenTracing as a required dependency. We still support OpenTracing, so you can do
  `OpenTracing\GlobalTracer::get()` in your code and still retrieve a OpenTracing compliant tracer, but
  OpenTracing dependency is now optional.
- We introduced auto-instrumentation and 1-step installation in place of manual registration of providers/bundles.
  Before, in order to see traces, you had to install our extension, add two dependencies to the composer file and
  add a provider (Laravel) or a bundle (Symfony). Starting from now you will only have to install the extension. You
  still have freedom to manually instrument the code, but only for advanced usage.

### Added
- Request init hook configuration allowing running arbitrary code before actual request execution #175
- Support OpenTracing without depending on it #193
- Initial C extension PHP 5.4 support #205
- Removal of external dependencies to support auto-instrumentation #206
- Migration from namespace based constants to class based constants for tags, formats and types #207
- Track integration loading to avoid re-loading unnecessary ones #211
- Documenting release steps #223
- Ability to run web framework tests in external web server #232
- Support for auto-instrumentation #237
- Support for Zend Framework 1 #238
- `Tracer::startRootSpan()` to track the root `Scope` instance which can be accessed with `Tracer::getRootScope()` #241

### Fixed
- The INI settings now appear in `phpinfo()` and when running `$ php -i` #242

## [0.9.1]
### Added
- Ability to reset all overrides via `dd_trace_reset`

### Changed
- By default do not throw an exception when method or function doesn't exist

### Fixed
- Eloquent integration calling protected `performInsert` method

## [0.9.0]
### Added
- PHP code compatibility with PHP 5.4 #194
- Move framework tests to tests root folder #198
- Move integrations tests to tests root folder #200
- Removal of external dependencies to support auto-instrumentation #206
- Allow testing of multiple library versions #203
- Downgrade of phpunit to 4.* in order to prepare for php 5.4 #208
- Configurable autofinishing of unfinished spans on tracer flush #217

### Fixed
- Predis integration supporting constructor options as an object #187 - thanks @raffaellopaletta
- Properly set http status code tag in Laravel 4 integration #195
- Agent calls traced when using Symfony 3 integration #197
- Fix for trace and span ID's that were improperly serialized on the wire in distributed tracing contexts #204
- Fix noop tracer issues with Laravel integration #220

## [0.8.1]
### Fixed
- Update Symfony 3 and 4 docs #184
- Package installation on custom PHP setups lacking conf.d support #188

## [0.8.0] - 2018-12-11
### Added
- Support for Lumen via the Laravel service provider #180
- Symfony 3.4 support #181

## [0.7.1] - 2018-12-07
### Added
- Symfony 3.4 and 4.2 sample apps #171

### Fixed
- Compatibility with PCS and using uninitialized data in some edge cases resulting in a SEGFAULT #173

## [0.7.0] - 2018-12-06
### Added
- Possibility to enable/disable distributed tracing and priority sampling #160
- Tracing for the [legacy MongoDB extension](http://php.net/manual/en/book.mongo.php) for PHP 5 #166
- Injecting distributed tracing headers in guzzle and curl requests #167
- Possibility to autoload all integrations and to disable specific ones #168
- Priority Sampling handling #169

### Fixed
- "Undefined offset: 0" errors in ElasticSearch integration #165

## [0.6.0] - 2018-12-03
### Added
- Guzzle and Curl enabling for Laravel integrations #161

## [0.5.1] - 2018-11-30
### Fixed
- Laravel pipelines tracer supporting configurable handler method #158

## [0.5.0] - 2018-11-29
### Added
- Changelog #152
- Custom PHP info output for ddtrace module #63 - thanks @SammyK
- guzzle v5 integration #148
- `static-analyze` to composer scripts #137
- distributed tracing initial support - without sampling priority #145
- curl integration #147
- Ignore Closure in laravel #125 - thanks @Sh4d1
- elastic search v1.x client integration #154

### Fixed
- `DDTrace\Tracer` returning a `DDTrace\NoopSpan` in place of `OpenTracing\NooSpan` when disabled #155
- PHP 5.6 ZTS builds #153

## [0.4.2] - 2018-11-21
### Added
- Laravel 4.2 and 5.7 tests coverage : #139

### Fixed
- Deprecated method `Span::setResource()` not part of `OpenTracing\Span`: #141 (Fixes #140)
- Laravel integration using HttpFoundation to retrieve status code: #142 - thanks @funkjedi
- SynfonyBundle using `getenv()` in place of `$_ENV` to read env variables: #143 - thanks @hinrik

## [0.4.1] - 2018-11-21
### Fixed
- Memcached key obfuscation: #132
- support tracing of Eloquent 4.2: #136
- support tracing calls to internal functions: #126
- Symfony exception handling and meta tags for request: #129 - thanks @jkrnak
- Symfony docs: #134 - thanks @inverse

## [0.4.0] - 2018-11-19
### Added
- Laravel 4.2 initial support

## [0.3.1] - 2018-11-16
### Fixed
- Laravel 5 secondary intergations pointing to non-existing classes: #127

## [0.3.0] - 2018-11-15
### Added
- support for PHP 5.6 🎉 #97 , #122
- Mysqli Integration: #104 - thanks @chuck
- Laravel improved pipeline tracing: #117
- ability to configure agent's connection parameters through env variables: #111
- PDO integration tests: #101
- Memcached integration tests: #88
- improvements to testing utils: #100 , #89
- improvements to the ci workflow: #102
- badges to README.md: #99 - thanks @inverse

### Changed
- Predis integration tests coverage: #110

### Fixed
- Laravel preventing traces from being sent when app name is empty: #112 - thanks @stayallive
- error message in SymfonyBundle.php when ddtrace extension is not loaded: #98 - thanks @inverse
- ext-json required dependency to composer.json: #103 - thanks @inverse
- Laravel service name from env variable: #118 - thanks @Sh4d1

## [0.2.7] - 2018-11-15
### Added
- span type to Symfony and Laravel integration
- post-install script checking if extension is successfully enabled

### Fixed
- memory leaks on request finalization

## [0.2.6] - 2018-10-25
### Changed
- ext-ddtrace is no longer required when installing via composer

### Fixed
- exception handling in C extension (PHP 5.6)

## [0.2.5] - 2018-10-22
### Fixed
- handling of function return values in (PHP 5.6)

## [0.2.4] - 2018-10-18
### Fixed
- instrumenting method name in mixed case (PHP 5.6)

## [0.2.3] - 2018-10-16
### Fixed
- compatibility in Laravel user tracking (PHP 5.6)
- linking on older GCC (Debian Stretch)

## [0.2.2] - 2018-10-15
### Fixed
- Laravel template rendering method signature missmatch

## [0.2.1] - 2018-10-15
### Fixed
- Laravel template rendering tracing
- PDO execute without parameters

## [0.2.0] - 2018-10-15
### Added
- ddtrace C extension to allow introspection into running PHP code
- initial Laravel auto instrumentation integration
- initial Symfony auto instrumentation integration
- initial Eloquent auto instrumentation integration
- initial Memcached auto instrumentation integration
- initial PDO auto instrumentation integration
- initial Predis auto instrumentation integration

## [0.1.2] - 2018-08-01
### Fixed
- Opentracing dependency so it can be installed without modifying minimum-stability.

## [0.1.1] - 2018-08-1
### Added
- added a Resource transport for debugging trace data

### Changed
- dependency cleanup

### Fixed
- error "Undefined offset: 1" when using Tracer::startActiveSpan
- Composer polyfill installation conflict
- Curl outputing to STDOUT when reporting to the trace agent

## [0.1.0] - 2018-08-01
### Added
- OpenTracing compliance tha can be used for manual instrumentation

[Unreleased]: https://github.com/DataDog/dd-trace-php/compare/0.30.1...HEAD
[0.30.1]: https://github.com/DataDog/dd-trace-php/compare/0.30.0...0.30.1
[0.30.0]: https://github.com/DataDog/dd-trace-php/compare/0.29.0...0.30.0
[0.29.0]: https://github.com/DataDog/dd-trace-php/compare/0.28.1...0.29.0
[0.28.1]: https://github.com/DataDog/dd-trace-php/compare/0.28.0...0.28.1
[0.28.0]: https://github.com/DataDog/dd-trace-php/compare/0.27.2...0.28.0
[0.27.2]: https://github.com/DataDog/dd-trace-php/compare/0.27.1...0.27.2
[0.27.1]: https://github.com/DataDog/dd-trace-php/compare/0.27.0...0.27.1
[0.27.0]: https://github.com/DataDog/dd-trace-php/compare/0.26.0...0.27.0
[0.26.0]: https://github.com/DataDog/dd-trace-php/compare/0.25.0...0.26.0
[0.25.0]: https://github.com/DataDog/dd-trace-php/compare/0.24.0...0.25.0
[0.24.0]: https://github.com/DataDog/dd-trace-php/compare/0.23.0...0.24.0
[0.23.0]: https://github.com/DataDog/dd-trace-php/compare/0.22.0...0.23.0
[0.22.0]: https://github.com/DataDog/dd-trace-php/compare/0.21.0...0.22.0
[0.21.0]: https://github.com/DataDog/dd-trace-php/compare/0.20.0...0.21.0
[0.20.0]: https://github.com/DataDog/dd-trace-php/compare/0.19.1...0.20.0
[0.19.1]: https://github.com/DataDog/dd-trace-php/compare/0.19.0...0.19.1
[0.19.0]: https://github.com/DataDog/dd-trace-php/compare/0.18.0...0.19.0
[0.18.0]: https://github.com/DataDog/dd-trace-php/compare/0.17.0...0.18.0
[0.17.0]: https://github.com/DataDog/dd-trace-php/compare/0.16.1...0.17.0
[0.16.1]: https://github.com/DataDog/dd-trace-php/compare/0.16.0...0.16.1
[0.16.0]: https://github.com/DataDog/dd-trace-php/compare/0.15.1...0.16.0
[0.15.1]: https://github.com/DataDog/dd-trace-php/compare/0.15.0...0.15.1
[0.15.0]: https://github.com/DataDog/dd-trace-php/compare/0.14.2...0.15.0
[0.14.2]: https://github.com/DataDog/dd-trace-php/compare/0.14.1...0.14.2
[0.14.1]: https://github.com/DataDog/dd-trace-php/compare/0.14.0...0.14.1
[0.14.0]: https://github.com/DataDog/dd-trace-php/compare/0.13.4...0.14.0
[0.13.4]: https://github.com/DataDog/dd-trace-php/compare/0.13.3...0.13.4
[0.13.3]: https://github.com/DataDog/dd-trace-php/compare/0.13.2...0.13.3
[0.13.2]: https://github.com/DataDog/dd-trace-php/compare/0.13.1...0.13.2
[0.13.1]: https://github.com/DataDog/dd-trace-php/compare/0.13.0...0.13.1
[0.13.0]: https://github.com/DataDog/dd-trace-php/compare/0.12.2...0.13.0
[0.12.2]: https://github.com/DataDog/dd-trace-php/compare/0.12.1...0.12.2
[0.12.1]: https://github.com/DataDog/dd-trace-php/compare/0.12.0...0.12.1
[0.12.0]: https://github.com/DataDog/dd-trace-php/compare/0.11.0...0.12.0
[0.11.0]: https://github.com/DataDog/dd-trace-php/compare/0.10.0...0.11.0
[0.10.0]: https://github.com/DataDog/dd-trace-php/compare/0.9.1...0.10.0
[0.9.1]: https://github.com/DataDog/dd-trace-php/compare/0.9.0...0.9.1
[0.9.0]: https://github.com/DataDog/dd-trace-php/compare/0.8.1...0.9.0
[0.8.1]: https://github.com/DataDog/dd-trace-php/compare/0.8.0...0.8.1
[0.8.0]: https://github.com/DataDog/dd-trace-php/compare/0.7.1...0.8.0
[0.7.1]: https://github.com/DataDog/dd-trace-php/compare/0.7.0...0.7.1
[0.7.0]: https://github.com/DataDog/dd-trace-php/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/DataDog/dd-trace-php/compare/0.5.1...0.6.0
[0.5.1]: https://github.com/DataDog/dd-trace-php/compare/0.5.0...0.5.1
[0.5.0]: https://github.com/DataDog/dd-trace-php/compare/0.4.2...0.5.0
[0.4.2]: https://github.com/DataDog/dd-trace-php/compare/0.4.1...0.4.2
[0.4.1]: https://github.com/DataDog/dd-trace-php/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/DataDog/dd-trace-php/compare/0.3.1...0.4.0
[0.3.1]: https://github.com/DataDog/dd-trace-php/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/DataDog/dd-trace-php/compare/0.2.7...0.3.0
[0.2.7]: https://github.com/DataDog/dd-trace-php/compare/0.2.6...0.2.7
[0.2.6]: https://github.com/DataDog/dd-trace-php/compare/0.2.5...0.2.6
[0.2.5]: https://github.com/DataDog/dd-trace-php/compare/0.2.4...0.2.5
[0.2.4]: https://github.com/DataDog/dd-trace-php/compare/0.2.3...0.2.4
[0.2.3]: https://github.com/DataDog/dd-trace-php/compare/0.2.2...0.2.3
[0.2.2]: https://github.com/DataDog/dd-trace-php/compare/0.2.1...0.2.2
[0.2.1]: https://github.com/DataDog/dd-trace-php/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/DataDog/dd-trace-php/compare/v0.1.2...0.2.0
[0.1.2]: https://github.com/DataDog/dd-trace-php/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/DataDog/dd-trace-php/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/DataDog/dd-trace-php/compare/29ee1d9f9fac5076cd0c96f8b8e9b205f15fb660...v0.1.0
