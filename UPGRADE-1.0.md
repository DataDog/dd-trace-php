# Upgrade to dd-trace-php 1.0

**Important Notice:** Upgrading `dd-trace-php` from version 0.x to 1.0 involves several breaking changes. Please review the following changes carefully before proceeding with the upgrade.


Version 1.0 of dd-trace-php brings significant enhancements and improvements over the previous versions. Alongside various bug fixes and performance optimizations, this release introduces a revamped WordPress integration, modifications to defaults, and deprecation of OpenTracing in favor of OpenTelemetry.

---

## Changes Summary

- [PHP 5 EOL](#php-5-eol)
- [WordPress](#wordpress)
    - [Configuration Changes](#configuration-changes)
    - [Breaking Changes](#breaking-changes)
- [Context Propagation](#context-propagation)
- [Sampling Rules](#sampling-rules)
- [OpenTracing Deprecation](#opentracing-deprecation)
- [Removed APIs and Configuration Keys](#removed-apis-and-configuration-keys)

## Migration Guide

To ensure a smooth transition to version 1.0, follow these migration steps:
- **Check PHP Version**: Ensure your PHP version is at least 7, as PHP 5 is no longer supported starting from version 1.0.
- **Update WordPress Integration**: If you're using the WordPress integration, adjust your configuration and monitors based on the changes outlined in the document.
- **Review Sampling Rules**: Ensure your sampling rules are configured correctly, considering the switch from regex patterns to glob patterns.
- **Update Deprecated APIs and Configuration Keys**: Replace deprecated functions, interfaces, classes, and configuration keys with their respective replacements as detailed in the document.
- **Test Thoroughly**: Test your application thoroughly after the upgrade to ensure that all functionalities are working as expected.

**Note**: To ensure a smoother transition and identify potential issues, you may enable debug logs (`DD_TRACE_DEBUG=1`) to see deprecations before migrating.

---

## PHP 5 EOL

Starting from version 1.0, support for PHP 5 is removed. If you are using PHP 5, you can still use the PHP tracer up to version 0.99. The minimum PHP version requirement for `dd-trace-php` 1.x is PHP 7.

## WordPress

An opt-in improvement to the WordPress integration was released as a public-beta in [0.91.0](https://github.com/DataDog/dd-trace-php/releases/tag/0.91.0). Starting version 1.0, the PHP tracer will adopt this integration.

### Configuration Changes

| Configuration Option                      | Previous Configuration Value | New Configuration Value |
|-------------------------------------------|------------------------------|-------------------------|
| `DD_TRACE_WORDPRESS_ENHANCED_INTEGRATION` | `false`                      | **Removed** (`true`)    |
| `DD_TRACE_WORDPRESS_CALLBACKS`            | `false`                      | `true`                  |

### Breaking Changes

While changes from the new WordPress Integration are mostly additive, some changes have been made to existing spans which could have an influence on your monitors, if any.

#### Replaced Spans

- `wp_print_footer_scripts`: If `DD_TRACE_WORDPRESS_CALLBACKS` is enabled, the operation name (resp. resource name) is changed from `wp_print_footer_scripts` to `callback` (resp. `wp_print_footer_scripts (callback)`).

- `wp_head`: The operation name (resp. resource name) is changed from `wp_head` to `action` (resp. `wp_head (hook)`).

- `load_template`: The resource name changed from `<template>` to `<template> (template)`.

#### Removed Spans

- `wpdb.__construct` and `wpdb.query`: Duplicate with the MySQL Integration spans.

- `WP_Widget.display_callback`: This method is removed in favor of tracing each `WP_Widget::widget` calls which happen during `WP_Widget.display_callback`.

## Context Propagation

|                                      | 0.x                                        | 1.0                                        |
|--------------------------------------|--------------------------------------------|--------------------------------------------|
| `DD_TRACE_PROPAGATION_STYLE_EXTRACT` | `tracecontext,datadog,B3,B3 single header` | `datadog,tracecontext,B3,B3 single header` |
| `DD_TRACE_PROPAGATION_STYLE_INJECT`  | `tracecontext,datadog`                     | `datadog,tracecontext`                     |
| `DD_TRACE_PROPAGATION_STYLE`         | `tracecontext,datadog`                     | `datadog,tracecontext`                     |

## Sampling Rules

Starting from version 1.0, the library will parse `DD_TRACE_SAMPLING_RULES` as `glob` patterns by default as regexes won't be supported in sampling rules across Datadog libraries.

You can still set `DD_TRACE_SAMPLING_RULES_FORMAT` to `regex` if you want to use regex sampling rules. Note that `DD_TRACE_SAMPLING_RULES_FORMAT` becomes deprecated and will be dropped in the next PHP major release.

## OpenTracing Deprecation

Custom instrumentation with OpenTracing is now deprecated. It is recommended to switch to [OpenTelemetry](https://github.com/open-telemetry/opentelemetry-php/tree/main) instead.

## Removed APIs and Configuration Keys

Deprecated functions, interfaces, classes, and config knobs were removed. The removals are outlined below.

### API Changes

| 0.x                                          | 1.0 Migration         |
|----------------------------------------------|-----------------------|
| `dd_trace_push_span_id`                      | `\DDTrace\start_span` |
| `dd_trace_generate_id`                       | `\DDTrace\start_span` |
| `dd_trace_pop_span_id`                       | `\DDTrace\close_span` |
| `dd_trace_forward_call`                      | Removed               |
| `additional_trace_meta`                      | Removed               |
| `dd_tracer_circuit_breaker_register_error`   | Removed               |
| `dd_tracer_circuit_breaker_register_success` | Removed               |
| `dd_tracer_circuit_breaker_can_try`          | Removed               |
| `dd_tracer_circuit_breaker_info`             | Removed               |

As well as everything that may have been marked as `@deprecated` in the Legacy PHP API (e.g., `DDTrace\Http\Request`).

### Configuration Changes

| 0.x                                                         | 1.0 Migration                                        |
|-------------------------------------------------------------|------------------------------------------------------|
| `DD_INTEGRATIONS_DISABLED`                                  | `DD_TRACE_[INTEGRATION]_ENABLE=false`                |
| `DD_SERVICE_NAME`                                           | `DD_SERVICE`                                         |
| `DD_TRACE_APP_NAME`                                         | `DD_SERVICE`                                         |
| `ddtrace_app_name` (ini)                                    | `DD_SERVICE`                                         |
| `DD_TRACE_GLOBAL_TAGS`                                      | `DD_TAGS`                                            |
| `DD_TRACE_RESOURCE_URI_MAPPING`                             | `DD_TRACE_RESOURCE_URI_MAPPING_[INCOMING\|OUTGOING]` |
| `DD_SAMPLING_RATE`                                          | `DD_TRACE_SAMPLE_RATE`                               |
| `DD_PROPAGATION_STYLE_INJECT`                               | `DD_TRACE_PROPAGATION_STYLE_INJECT`                  |
| `DD_PROPAGATION_STYLE_EXTRACT`                              | `DD_TRACE_PROPAGATION_STYLE_EXTRACT`                 |
| `DDTRACE_REQUEST_INIT_HOOK`                                 | Removed                                              |
| `DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES`                   | Removed                                              |
| `DD_TRACE_CIRCUIT_BREAKER_DEFAULT_MAX_CONSECUTIVE_FAILURES` | Removed                                              |
| `DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC`                    | Removed                                              |
| `DD_TRACE_CIRCUIT_BREAKER_DEFAULT_RETRY_TIME_MSEC`          | Removed                                              |

---

## Feedback and Support
If you encounter any issues during the upgrade process or have suggestions for improvement, please don't hesitate to reach out to the DataDog team for assistance. Your feedback is valuable in helping us improve the `dd-trace-php` library.
- [Datadog Documentation](https://docs.datadoghq.com/)
- [Datadog Support](https://www.datadoghq.com/support/)
- [GitHub Issues](https://github.com/DataDog/dd-trace-php/issues)
