--TEST--
ASAN regression: self-removing HOOK_INSTANCE on fake closures
--DESCRIPTION--
This test exists because we observed deterministic AddressSanitizer crashes on
PHP 8.1 debug-zts-asan when exercising hook teardown for "fake closures"
created via Reflection.

The core pattern is:
- Create a fake closure from Reflection (user function, user method, and an
  internal function).
- Install a `DDTrace\HOOK_INSTANCE` hook on that closure.
- In the posthook, immediately remove the hook (self-removal while unwinding
  the call).

On the affected builds, this can corrupt/invalidly access hook bookkeeping such
that the tracer later attempts to compute a hook address from a NULL/invalid
`zend_function`, leading to an ASAN SEGV in `zai_hook_install_address()`.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) {
    die('skip: requires PHP 8+');
}
if (!extension_loaded('ddtrace')) {
    die('skip: ddtrace extension required');
}
?>
--INI--
; Keep the runtime as "quiet" as possible so failures are about the hook
; teardown path.
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=0
datadog.trace.sidecar_trace_sender=0
datadog.instrumentation_telemetry_enabled=Off
opcache.enable=0
opcache.enable_cli=0
--FILE--
<?php
function foo(int $x): int { return $x + 1; }
class K {
    public static function bar(int $x): int { return $x + 2; }
}

$closures = [
    (new ReflectionFunction('foo'))->getClosure(),
    (new ReflectionClass(K::class))->getMethod('bar')->getClosure(),
    (new ReflectionFunction('intval'))->getClosure(),
];

foreach ($closures as $c) {
    $id = null;
    $id = DDTrace\install_hook(
        $c,
        null,
        static function () use (&$id): void {
            if ($id !== null) {
                DDTrace\remove_hook($id);
                $id = null;
            }
        },
        DDTrace\HOOK_INSTANCE
    );

    // Trigger the hook; the posthook removes itself during this call.
    $c(1);

    // Encourage closure teardown paths between iterations.
    unset($c);
}

gc_collect_cycles();
echo "ok\n";
?>
--EXPECT--
ok

