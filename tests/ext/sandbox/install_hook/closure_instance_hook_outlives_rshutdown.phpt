--TEST--
Instance-hooked closures that outlive RSHUTDOWN are freed safely
--DESCRIPTION--
These closures are only freed in shutdown_executor: one through a static property, and on
PHP 8.6+ the cached static closure also through the engine's lambda cache. The Closure
free_obj wrapper then looks up uhook_closure_hooks, which must still be alive (it is only
destroyed in post_deactivate). Destroying it in RSHUTDOWN instead fails this test with a
heap-use-after-free under ASAN and a leak report on debug builds.
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

class Holder { public static $closures = []; }

function cached() { return static function () { return "cached"; }; }
function captured($x) { return static function () use ($x) { return $x; }; }

foreach ([cached(), captured("captured")] as $closure) {
    DDTrace\install_hook($closure, function () {
        echo "begin\n";
    }, null, DDTrace\HOOK_INSTANCE);
    DDTrace\install_hook($closure, null, function (DDTrace\HookData $hook) {
        echo "end: {$hook->returned}\n";
    }, DDTrace\HOOK_INSTANCE);
    Holder::$closures[] = $closure;
    $closure();
}
unset($closure);

register_shutdown_function(function () {
    foreach (Holder::$closures as $closure) {
        $closure();
    }
});

echo "end of script\n";

?>
--EXPECT--
begin
end: cached
begin
end: captured
end of script
begin
end: cached
begin
end: captured
