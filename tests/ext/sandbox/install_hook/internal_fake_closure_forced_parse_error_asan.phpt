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

\DDTrace\install_hook(
    $closure,
    function () {},
    function () {
        // Intentionally invalid PHP code to force a ParseError from eval().
        eval('class Broken {');
    },
    \DDTrace\HOOK_INSTANCE
);

$callable = $closure;
$callable(1);

echo "ok\n";
?>
--EXPECT--
ok

