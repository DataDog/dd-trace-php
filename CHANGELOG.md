# Datadog AppSec for PHP Release

### v0.8.0
#### Fixes
- ([#230](https://github.com/DataDog/dd-appsec-php/pull/230)) Amend issue when getting agent host and port
- ([#233](https://github.com/DataDog/dd-appsec-php/pull/233)) Flush socket on body limit
- ([#245](https://github.com/DataDog/dd-appsec-php/pull/245)) Set appsec disabled when ddtrace is not enabled
- ([#246](https://github.com/DataDog/dd-appsec-php/pull/246)) Cap retry to five minutes rc polling
- ([#250](https://github.com/DataDog/dd-appsec-php/pull/250)) Avoid updating waf when no updates provided on poll

#### Additions
- ([#227](https://github.com/DataDog/dd-appsec-php/pull/227)) Refactor capabilities
- ([#229](https://github.com/DataDog/dd-appsec-php/pull/229)) Refactor service
- ([#235](https://github.com/DataDog/dd-appsec-php/pull/235)) Custom rules support
- ([#237](https://github.com/DataDog/dd-appsec-php/pull/237)) Update ip algorithm
- ([#249](https://github.com/DataDog/dd-appsec-php/pull/249)) Handle request-lifecycle atomically
- ([#248](https://github.com/DataDog/dd-appsec-php/pull/248)) Engine update batcher
- ([#253](https://github.com/DataDog/dd-appsec-php/pull/253)) Update-only engine listener and atomic config handling 

#### Miscellaneous Changes
- ([#226](https://github.com/DataDog/dd-appsec-php/pull/226)) Upgrade tracer to 0.85.0
- ([#228](https://github.com/DataDog/dd-appsec-php/pull/228)) Setup python 3.9 for system tests
- ([#234](https://github.com/DataDog/dd-appsec-php/pull/234)) Update WAF to 1.9.0, Ruleset to 1.6.0 and Tracer to 0.86.1
- ([#236](https://github.com/DataDog/dd-appsec-php/pull/236)) Disable apache2 restart test on ZTS
- ([#239](https://github.com/DataDog/dd-appsec-php/pull/239)) Implement abstract methods of listener on test
- ([#240](https://github.com/DataDog/dd-appsec-php/pull/240)) Add test to ensure path parser does not count on /config ending
- ([#252](https://github.com/DataDog/dd-appsec-php/pull/252)) Update ruleset to 1.7.0
- ([#254](https://github.com/DataDog/dd-appsec-php/pull/254)) Update html blocked template
- ([#255](https://github.com/DataDog/dd-appsec-php/pull/255)) Upgrade WAF to 1.10.0 and add custom_rules capability 

### v0.7.2
#### Fixes
- ([#233](https://github.com/DataDog/dd-appsec-php/pull/233)) Flush socket on body limit

### v0.7.1
#### Fixes
- ([#231](https://github.com/DataDog/dd-appsec-php/pull/231)) Fallback to default agent host and port.
- ([#231](https://github.com/DataDog/dd-appsec-php/pull/231)) Support `DD_TRACE_AGENT_URL`

### v0.7.0
#### Breaking Changes
 - ([#182](https://github.com/DataDog/dd-appsec-php/pull/182)) Delete `enabled_on_cli` ini setting

#### Fixes
 - ([#183](https://github.com/DataDog/dd-appsec-php/pull/183)) Add uid and gid to sock and lock files

#### Additions
 - ([#115](https://github.com/DataDog/dd-appsec-php/pull/115)) Remote configuration client
 - ([#163](https://github.com/DataDog/dd-appsec-php/pull/163)) Plug remote config service
 - ([#164](https://github.com/DataDog/dd-appsec-php/pull/164)) Add `config_sync` helper command
 - ([#188](https://github.com/DataDog/dd-appsec-php/pull/188)) Add ASM\_DATA Product Listener
 - ([#188](https://github.com/DataDog/dd-appsec-php/pull/188)) IP Blocking
 - ([#195](https://github.com/DataDog/dd-appsec-php/pull/195)) Redirect support
 - ([#196](https://github.com/DataDog/dd-appsec-php/pull/196)) Add `request_exec` helper command
 - ([#207](https://github.com/DataDog/dd-appsec-php/pull/207)) ASM\_DD Product Listener
 - ([#210](https://github.com/DataDog/dd-appsec-php/pull/210)) ASM Product Listener
 - ([#210](https://github.com/DataDog/dd-appsec-php/pull/210)) Rule Blocking
 - ([#212](https://github.com/DataDog/dd-appsec-php/pull/212)) Check if RC is available before polling
 - ([#213](https://github.com/DataDog/dd-appsec-php/pull/213)) User Blocking

#### Miscellaneous Changes
 - ([#184](https://github.com/DataDog/dd-appsec-php/pull/184)) Support actions and refactor
 - ([#186](https://github.com/DataDog/dd-appsec-php/pull/186)) Update `engine::subscriber` rule data
 - ([#187](https://github.com/DataDog/dd-appsec-php/pull/187)) Blocking templates, missing traces fix and set blocking parameters
 - ([#193](https://github.com/DataDog/dd-appsec-php/pull/193)) Upgrade tracer to v0.84.0
 - ([#202](https://github.com/DataDog/dd-appsec-php/pull/202)) Upgrade WAF 1.8.2
 - ([#208](https://github.com/DataDog/dd-appsec-php/pull/208)) Add init / commit stage to listeners
 - ([#213](https://github.com/DataDog/dd-appsec-php/pull/213)) Ruleset 1.5.2
 - ([#215](https://github.com/DataDog/dd-appsec-php/pull/215)) Allow new and old default rules file to be loaded
 - ([#216](https://github.com/DataDog/dd-appsec-php/pull/216)) Fallback to local IP on extraction

### v0.6.0
#### Breaking Changes
 - ([#177](https://github.com/DataDog/dd-appsec-php/pull/177)) Update SDK with separate success/failure functions

#### Miscellaneous Changes
 - ([#176](https://github.com/DataDog/dd-appsec-php/pull/176)) Upgrade deprecated actions and ruleset to 1.4.3

### v0.5.0
#### Fixes
 - ([#120](https://github.com/DataDog/dd-appsec-php/pull/120)) Return error response in helper when incoming message can't be adequately handled
 - ([#124](https://github.com/DataDog/dd-appsec-php/pull/124)) Avoid creating log file as root
 - ([#130](https://github.com/DataDog/dd-appsec-php/pull/130)) Reset context on shutdown
 - ([#132](https://github.com/DataDog/dd-appsec-php/pull/132)) Handle errors on `request_shutdown`
 - ([#170](https://github.com/DataDog/dd-appsec-php/pull/170)) Avoid regenerating ip when multiple headers are already present

#### Additions
 - ([#114](https://github.com/DataDog/dd-appsec-php/pull/114)) Add zai config
 - ([#128](https://github.com/DataDog/dd-appsec-php/pull/128)) Replace `actor.ip` with `http.client_ip`
 - ([#151](https://github.com/DataDog/dd-appsec-php/pull/151)) PHP 8.2RC support
 - ([#155](https://github.com/DataDog/dd-appsec-php/pull/155)) Generate IP on appsec
 - ([#166](https://github.com/DataDog/dd-appsec-php/pull/166)) Support PHP 8.2 Release
 - ([#174](https://github.com/DataDog/dd-appsec-php/pull/174)) Login and custom event SDK

#### Miscellaneous Changes
 - ([#117](https://github.com/DataDog/dd-appsec-php/pull/117)) Upgrade WAF to 1.5.0 and ruleset to 1.4.0
 - ([#125](https://github.com/DataDog/dd-appsec-php/pull/125)) Update ip extraction module
 - ([#129](https://github.com/DataDog/dd-appsec-php/pull/129)) Make test use latest version of ddtrace 0.79.0
 - ([#142](https://github.com/DataDog/dd-appsec-php/pull/142)) Update ddtrace-basic test to be compatible with older tracers
 - ([#152](https://github.com/DataDog/dd-appsec-php/pull/152)) Fix package / release build
 - ([#153](https://github.com/DataDog/dd-appsec-php/pull/153)) Update LLVM script
 - ([#172](https://github.com/DataDog/dd-appsec-php/pull/172)) Fix package build
 - ([#175](https://github.com/DataDog/dd-appsec-php/pull/175)) WAF upgrade to 1.6.0 and ruleset to 1.4.2

### v0.4.5
#### Miscellaneous Changes
- ([#154](https://github.com/DataDog/dd-appsec-php/pull/154)) Support for PHP 8.2.0RC6

### v0.4.4
#### Additions
- ([#141](https://github.com/DataDog/dd-appsec-php/pull/141)) Generate ip and duplicate ip headers on appsec

### v0.4.3
#### Fixes
- ([#132](https://github.com/DataDog/dd-appsec-php/pull/132)) Handle errors on request shutdown

### v0.4.2
#### Fixes
- ([#127](https://github.com/DataDog/dd-appsec-php/pull/127)) Handle helper errors gracefully
- ([#130](https://github.com/DataDog/dd-appsec-php/pull/130)) Reset context on shutdown

### v0.4.1
#### Fixes
- ([#120](https://github.com/DataDog/dd-appsec-php/pull/120)) Return error response in helper when incoming message can't be unpacked
- ([#124](https://github.com/DataDog/dd-appsec-php/pull/124)) Avoid creating a log file during MINIT/MSHUTDOWN

#### Miscellaneous Changes
- ([#123](https://github.com/DataDog/dd-appsec-php/pull/123)) Enable CI on all relevant branches

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
