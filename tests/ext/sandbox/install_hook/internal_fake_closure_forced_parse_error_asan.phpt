--TEST--
ASAN repro: internal fake closure + forced eval parse error
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) {
    die('skip: test requires PHP 8+');
}
?>
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=0
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--FILE--
<?php
$closure = (new ReflectionFunction("intval"))->getClosure();
$hookId = null;

$hookId = \DDTrace\install_hook(
    $closure,
    function () {},
    function () use (&$hookId) {
        // Force eval() error path (deterministic ASAN crash site).
        eval('class Broken {');

        // Keep the hook installed; crash occurs during backtrace collection.
    },
    \DDTrace\HOOK_INSTANCE
);

$callable = $closure;
$callable(1);

echo "ok\n";
?>
--EXPECT--
ok

