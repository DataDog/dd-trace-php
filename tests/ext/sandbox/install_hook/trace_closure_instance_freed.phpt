--TEST--
Freeing an instance-hooked static closure removes its hook
--DESCRIPTION--
On PHP 8.6 the engine keeps a cached static closure until request end, so the first evaluation keeps
its hook. Once instance-hooked, later evaluations are fresh and live only as long as user references,
so their hooks go away with them: with hook_limit=2 each new install only succeeds if the previous
hook on a fresh closure was removed.
--INI--
datadog.trace.generate_root_span=0
datadog.trace.hook_limit=2
--FILE--
<?php

function cached() { return static function () { return 1; }; }

for ($i = 0; $i < 4; ++$i) {
    $closure = cached();
    var_dump(DDTrace\install_hook($closure, function () { echo "traced\n"; }, null, DDTrace\HOOK_INSTANCE) > 0);
    $closure();
    unset($closure);
}

?>
--EXPECT--
bool(true)
traced
bool(true)
traced
bool(true)
traced
bool(true)
traced
