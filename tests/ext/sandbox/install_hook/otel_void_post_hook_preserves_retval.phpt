--TEST--
OpenTelemetry hook polyfill: post hook with `: void` return type must not overwrite the function's return value
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip OpenTelemetry hook polyfill is only registered on PHP 8.0+');
if (!function_exists('OpenTelemetry\\Instrumentation\\hook')) die('skip OpenTelemetry\\Instrumentation\\hook polyfill not registered');
// When tracing is disabled at the CLI, ddtrace_post_deactivate runs before PHP
// releases the extra reference it holds on :void closure literals declared in
// the main script's op_array. The polyfill's GC_ADDREF/OBJ_RELEASE is balanced;
// PHP's residual ref is unrelated to the IS_VOID/MAY_BE_VOID fix this test
// guards, but exposes a leak on the test_c_disabled CI leg specifically.
if (!ini_get('datadog.trace.cli_enabled') || getenv('DD_TRACE_CLI_ENABLED') === '0') {
    die('skip see comment above');
}
?>
--INI--
datadog.trace.cli_enabled=1
datadog.trace.generate_root_span=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

class VoidPostTarget {
    public function handle(): string {
        return "expected-return-value";
    }
}

class TypedPostTarget {
    public function handle(): string {
        return "original-value";
    }
}

// :void post hook must NOT overwrite the function's retval.
// This is the regression test for the IS_VOID/MAY_BE_VOID bitmask bug.
\OpenTelemetry\Instrumentation\hook(
    VoidPostTarget::class,
    'handle',
    null,
    function ($obj, array $params, $retval, ?\Throwable $exception): void {}
);

var_dump((new VoidPostTarget())->handle());

// Typed-non-void post hook MUST overwrite retval with the closure's return.
// (Single hook on a distinct class to avoid LIFO stacking interactions.)
\OpenTelemetry\Instrumentation\hook(
    TypedPostTarget::class,
    'handle',
    null,
    function ($obj, array $params, $retval, ?\Throwable $exception): string { return "overridden-by-typed-hook"; }
);

var_dump((new TypedPostTarget())->handle());
?>
--EXPECT--
string(21) "expected-return-value"
string(24) "overridden-by-typed-hook"
