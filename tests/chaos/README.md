Investigation (up to `8f3b4a22b7487ce1b18e122d68d9a33279a06c38 chaos make it more parametrized and detect regression in 0.45.0`):

- Default: would have found critical regressions in 0.48.0, 0.48.1, 0.47.0 (on 5.4 commenting `dd_trace_method` in index.php which was not available yet)
- `DD_TRACE_ENABLED: true|false` --> would have found bug `0.48.2`
- Would NOT have found: regression introduced in 0.45.0 (psr4 compatility) unless we add manual instrumentation via composer.
- When `x-datadog-origin` added, it would have discovered #1070
