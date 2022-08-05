# Datadog AppSec for PHP Release

### v0.4.0
#### Fixes
- ([#99](https://github.com/DataDog/dd-appsec-php/pull/99)) Fix interned string invalidation on PHP <= 7.2
- ([#101](https://github.com/DataDog/dd-appsec-php/pull/101)) Replace `php_error_docref` with `php_log_err`

#### Additions
- ([#97](https://github.com/DataDog/dd-appsec-php/pull/97)) Log helper communication
- ([#105](https://github.com/DataDog/dd-appsec-php/pull/105)) Set environment values at rinit on php-fpm

#### Miscellaneous Changes
- ([#98](https://github.com/DataDog/dd-appsec-php/pull/98)) Update development documentation
- ([#102](https://github.com/DataDog/dd-appsec-php/pull/102)) Update system tests with new variants
- ([#108](https://github.com/DataDog/dd-appsec-php/pull/108)) Add helper test for config
- ([#109](https://github.com/DataDog/dd-appsec-php/pull/109)) Fix missing helper header coverage
- ([#110](https://github.com/DataDog/dd-appsec-php/pull/110)) Upgrade integration test tracer version to 0.76.1
- ([#111](https://github.com/DataDog/dd-appsec-php/pull/111)) Add a way to include local changes to the cmake build process
- ([#116](https://github.com/DataDog/dd-appsec-php/pull/116)) libddwaf upgraded to 1.4.0

### v0.3.2
#### Fixes
- ([#92](https://github.com/DataDog/dd-appsec-php/pull/92)) Fix hybrid extension initialisation

### v0.3.1
#### Fixes
- ([#86](https://github.com/DataDog/dd-appsec-php/pull/86)) Fix relative module order with ddtrace on PHP 7.3 ([#88](https://github.com/DataDog/dd-appsec-php/issues/88))

### v0.3.0
#### Breaking Changes
- ([#74](https://github.com/DataDog/dd-appsec-php/pull/74)) Rename ini settings from `datadog.appsec.rules_path` to `datadog.appsec.rules`
- ([#74](https://github.com/DataDog/dd-appsec-php/pull/74)) Interpret `datadog.appsec.waf_timeout` as microseconds rather than milliseconds

#### Fixes
- ([#83](https://github.com/DataDog/dd-appsec-php/pull/83)) Add obfuscator strings when initialising WAF from client settings

#### Additions
- ([#79](https://github.com/DataDog/dd-appsec-php/pull/79)) Add WAF metrics and errors to traces
- ([#80](https://github.com/DataDog/dd-appsec-php/pull/80)) Actor IP resolution from request headers
- ([#82](https://github.com/DataDog/dd-appsec-php/pull/82)) Add support for WAF event obfuscator
- ([#84](https://github.com/DataDog/dd-appsec-php/pull/84)) Add obfuscator regex for values

#### Miscellaneous Changes
- ([#76](https://github.com/DataDog/dd-appsec-php/pull/76)) Update installer links in documentation and tests
- ([#78](https://github.com/DataDog/dd-appsec-php/pull/78)) Add `parameter_view` for non-ownership of WAF parameters
- ([#81](https://github.com/DataDog/dd-appsec-php/pull/81)) Accept IP list on `X-Cluster-Client-IP` header
- ([#82](https://github.com/DataDog/dd-appsec-php/pull/82)) Update ruleset to v1.3.1
- ([#82](https://github.com/DataDog/dd-appsec-php/pull/82)) libddwaf upgraded to v1.3.0
- ([#84](https://github.com/DataDog/dd-appsec-php/pull/84)) Update installation instructions

### v0.2.2
#### Miscellaneous Changes
- ([#69](https://github.com/DataDog/dd-appsec-php/pull/69)) Add PHP FPM to system tests
- ([#70](https://github.com/DataDog/dd-appsec-php/pull/70)) Add a nightly run for system tests
- ([#71](https://github.com/DataDog/dd-appsec-php/pull/71)) libddwaf upgraded to v1.0.18
- ([#73](https://github.com/DataDog/dd-appsec-php/pull/73)) Update ruleset to v1.2.6

### v0.2.1
#### Miscellaneous Changes
- ([#64](https://github.com/DataDog/dd-appsec-php/pull/64)) Add system tests
- ([#65](https://github.com/DataDog/dd-appsec-php/pull/65)) Update ruleset to v1.2.5

### v0.2.0
#### Breaking Changes
- ([#44](https://github.com/DataDog/dd-appsec-php/pull/44)) Rename ini settings from `ddappsec.*` to `datadog.appsec.*`
- ([#54](https://github.com/DataDog/dd-appsec-php/pull/54)) Align ini and environment priorities with the tracer

#### Fixes
- ([#15](https://github.com/DataDog/dd-appsec-php/pull/15)) Fix daemon launch on `php-fpm -i`
- ([#55](https://github.com/DataDog/dd-appsec-php/pull/55)) Fix race condition when obtaining daemon UID/GID

#### Additions
- ([#2](https://github.com/DataDog/dd-appsec-php/pull/2)) Thread pool to reuse idle threads and reduce the client initialisation cost
- ([#3](https://github.com/DataDog/dd-appsec-php/pull/3)) Finalize daemon when idle for 24 hours
- ([#26](https://github.com/DataDog/dd-appsec-php/pull/26)) Add `DD_APPSEC_WAF_TIMEOUT` to allow configuration of the WAF timeout
- ([#53](https://github.com/DataDog/dd-appsec-php/pull/53)) Add client IP inferral from headers, configurable with `DD_APPSEC_IPHEADER`
- ([#61](https://github.com/DataDog/dd-appsec-php/pull/61)) Basic AppSec trace rate limiting in the daemon, using `DD_APPSEC_TRACE_RATE_LIMIT`

#### Miscellaneous Changes
- ([#1](https://github.com/DataDog/dd-appsec-php/pull/1)) Code scanning on daemon code.
- ([#14](https://github.com/DataDog/dd-appsec-php/pull/14)) The list of response headers transmitted in the trace now contains only:
	- Content-type
	- Content-length
	- Content-encoding
	- Content-language
- ([#18](https://github.com/DataDog/dd-appsec-php/pull/18)) Use clang-format and ensure copyright notices
- ([#23](https://github.com/DataDog/dd-appsec-php/pull/23)) Integration tests for PHP 8.1
- ([#33](https://github.com/DataDog/dd-appsec-php/pull/33)) Update standard logging to conform to the new specification
- ([#37](https://github.com/DataDog/dd-appsec-php/pull/37)) Improve daemon test coverage
- ([#45](https://github.com/DataDog/dd-appsec-php/pull/45)) Add coverage report to helper
- ([#52](https://github.com/DataDog/dd-appsec-php/pull/52)) Validate daemon version on `client_init` to ensure compatibility
- ([#57](https://github.com/DataDog/dd-appsec-php/pull/57)) Override libfuzzer main to provide better control over object lifetimes
- ([#58](https://github.com/DataDog/dd-appsec-php/pull/58)) Socket and lock file versioning to avoid communication with incompatible daemons
- ([#60](https://github.com/DataDog/dd-appsec-php/pull/60)) Disable blocking by default
- ([#60](https://github.com/DataDog/dd-appsec-php/pull/60)) Disable sending raw body to the daemon
- ([#62](https://github.com/DataDog/dd-appsec-php/pull/62)) libddwaf upgraded to v1.0.17
- ([#62](https://github.com/DataDog/dd-appsec-php/pull/62)) Updated ruleset to v1.2.4

### v0.1.0
- Initial release
