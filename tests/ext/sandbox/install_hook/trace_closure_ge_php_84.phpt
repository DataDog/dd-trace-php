--TEST--
Tracing Closures via install_hook()
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip: test only stable on PHP >= 8.4');
}
?>
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=0
--FILE--
<?php

namespace test;

$internalFakeClosure = (new \ReflectionFunction("intval"))->getClosure();

$topLevelClosure = function($a) {
    return $a + 1;
};

function foo() {
    return function($a) {
        return $a + 2;
    };
}

class bar {
    static function foo() {
        return function($a) {
            return $a + 3;
        };
    }
}

$closures = [$internalFakeClosure, $topLevelClosure, foo(), bar::foo()];
$hooks = [];

foreach ($closures as $i => $closure) {
    $hooks[] = \DDTrace\install_hook($closure, function(\DDTrace\HookData $hook) use ($i) {
        $hook->span()->resource = $i;
    }, function(\DDTrace\HookData $hook) {
        $hook->span()->meta['result'] = $hook->returned;
    }, \DDTrace\HOOK_INSTANCE);

    $closure(0);
}

foo()(0); // Not traced

// Still traced
foreach ($closures as $closure) {
    $closure(1);
}

foreach ($hooks as $hook) {
    \DDTrace\remove_hook($hook);
}

// Not traced
foreach ($closures as $closure) {
    $closure(2);
}

include __DIR__ . '/../dd_dumper.inc';
\dd_dump_spans(true);

?>
--EXPECTF--
spans(\DDTrace\SpanData) (8) {
  intval (trace_closure_ge_php_84.php, 0, cli)
    result => 0
    _dd.p.tid => %s
  {closure:%s.php:7\{closure} (trace_closure_ge_php_84.php, 1, cli)
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure_ge_php_84.php:7
    result => 1
    _dd.p.tid => %s
  test\foo.{closure} (trace_closure_ge_php_84.php, 2, cli)
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure_ge_php_84.php:12
    result => 2
    _dd.p.tid => %s
  test\bar.foo.{closure} (trace_closure_ge_php_84.php, 3, cli)
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure_ge_php_84.php:19
    result => 3
    _dd.p.tid => %s
  intval (trace_closure_ge_php_84.php, 0, cli)
    result => 1
    _dd.p.tid => %s
  {closure:%s.php:7\{closure} (trace_closure_ge_php_84.php, 1, cli)
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure_ge_php_84.php:7
    result => 2
    _dd.p.tid => %s
  test\foo.{closure} (trace_closure_ge_php_84.php, 2, cli)
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure_ge_php_84.php:12
    result => 3
    _dd.p.tid => %s
  test\bar.foo.{closure} (trace_closure_ge_php_84.php, 3, cli)
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure_ge_php_84.php:19
    result => 4
    _dd.p.tid => %s
}
