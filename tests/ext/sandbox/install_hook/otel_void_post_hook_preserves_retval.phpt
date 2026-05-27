--TEST--
OpenTelemetry hook polyfill: post hook with `: void` return type must not overwrite the function's return value
--INI--
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
    pre: null,
    post: static function ($obj, array $params, $retval, ?\Throwable $exception): void {
        // implicit null return; must NOT replace $retval
    }
);

var_dump((new VoidPostTarget())->handle());

// Typed-non-void post hook MUST overwrite retval with the closure's return.
// (Single hook on a distinct class to avoid LIFO stacking interactions.)
\OpenTelemetry\Instrumentation\hook(
    TypedPostTarget::class,
    'handle',
    pre: null,
    post: static function ($obj, array $params, $retval, ?\Throwable $exception): string {
        return "overridden-by-typed-hook";
    }
);

var_dump((new TypedPostTarget())->handle());
?>
--EXPECT--
string(21) "expected-return-value"
string(24) "overridden-by-typed-hook"
