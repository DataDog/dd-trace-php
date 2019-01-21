# Changelog
All notable changes to this project will be documented in this file - [read more](docs/changelog.md).

## [UNRELEASED]

## [0.10.0]

**WARNING: THIS IS  A BREAKING CHANGE RELEASE**

Refer to the [Migration Guide](UPGRADE-0.10.md) for a detailed description.

At an high level here are the breaking changes we introduced:

 - We removed OpenTracing as a required dependency. We still support OpenTracing, so you can do
   `OpenTracing<GlobalTracer::get()` in your code and still retrieve a OopenTracing compliant tracer, but
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

### Changed

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

[Unreleased]: https://github.com/DataDog/dd-trace-php/compare/0.10.0...HEAD
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
