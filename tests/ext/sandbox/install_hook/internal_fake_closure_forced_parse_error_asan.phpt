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
--FILE--
<?php
$closure = (new ReflectionFunction("intval"))->getClosure();

\DDTrace\install_hook(
    $closure,
    null,
    function () {
        eval('throw new \\Exception("boom");');
    },
    \DDTrace\HOOK_INSTANCE
);

try {
    $closure(1);
} catch (Throwable $e) {
    // ignore
}

echo "ok\n";
?>
--EXPECT--
ok

