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
$iterations = 1;
$callsPerIter = 1;

for ($i = 0; $i < $iterations; $i++) {
    $closureA = (new ReflectionFunction("intval"))->getClosure();
    $closureB = (new ReflectionFunction("intval"))->getClosure();

    $hookIdA = null;
    $hookIdB = null;

    $hookIdB = \DDTrace\install_hook(
        $closureB,
        function () {},
        function () {},
        \DDTrace\HOOK_INSTANCE
    );

    $hookIdA = \DDTrace\install_hook(
        $closureA,
        function () {},
        function () use ($i, $callsPerIter, &$hookIdA, &$hookIdB, $closureB) {
            if ($hookIdB !== null) {
                \DDTrace\remove_hook($hookIdB);
                $hookIdB = null;
            }

            // Force eval() error path (deterministic ASAN crash site).
            eval('class Broken {');

            // Re-enter via internal fake closure after removal.
            $callB = $closureB;
            for ($j = 0; $j < $callsPerIter; $j++) {
                $callB($i + $j);
            }

            if ($hookIdA !== null) {
                \DDTrace\remove_hook($hookIdA);
                $hookIdA = null;
            }
        },
        \DDTrace\HOOK_INSTANCE
    );

    $callA = $closureA;
    $callA($i);
}

echo "ok\n";
?>
--EXPECT--
ok

