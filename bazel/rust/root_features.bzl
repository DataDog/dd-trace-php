"""Root datadog-php Cargo features accepted by rustc check-cfg."""

_ROOT_FEATURES = (
    "debug_stats",
    "default",
    "helper-rust-coverage",
    "io_profiling",
    "profiling",
    "stack_walking_tests",
    "test",
    "tracer",
    "tracing",
    "tracing-subscriber",
    "trigger_time_sample",
)

ROOT_FEATURE_CHECK = "--check-cfg=cfg(feature,values(%s))" % ",".join([
    repr(feature)
    for feature in _ROOT_FEATURES
])
