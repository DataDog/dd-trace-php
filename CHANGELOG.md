Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Fixed
- Fix SSI MSHUTDOWN on PHP 7.3 with opcache #4169

## Tracer
### Fixed
- Set ZEND_ENABLE_STATIC_TSRMLS_CACHE=1 on windows as well for windows ZTS builds #4172
- Fix #4163: broken frankenphp shutdown signal on musl #4167

### Internal
- Remove any dependency on aws-lc-sys #4170

## Profiling
### Fixed
- Fix IO/allocation sampling intervals #4159

## AppSec
### Fixed
- Use MetricType::Distribution for RASP_RULE_DURATION_DIST #4093
- Do not panic when WAF arrays exceed 2^16-1 elements DataDog/libddwaf-rust#28
