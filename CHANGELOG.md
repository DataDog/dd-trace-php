# Datadog AppSec for PHP Release

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
