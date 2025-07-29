--TEST--
Tracing Closures via install_hook()
--SKIPIF--
<?php
if (PHP_VERSION_ID >= 80400) {
    die('skip: test only stable on PHP >= 8.4');
}
?>
--INI--
datadog.trace.generate_root_span=0
datadog.code_origin_for_spans_enabled=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
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
  intval (trace_closure.php, 0, cli)
    _dd.p.tid => %s
    result => 0
  test\trace_closure.php:7\{%s} (trace_closure.php, 1, cli)
    _dd.p.tid => %s
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure.php:7
    result => 1
  test\foo.{closure} (trace_closure.php, 2, cli)
    _dd.p.tid => %s
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure.php:12
    result => 2
  test\bar.foo.{closure} (trace_closure.php, 3, cli)
    _dd.p.tid => %s
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure.php:19
    result => 3
  intval (trace_closure.php, 0, cli)
    _dd.p.tid => %s
    result => 1
  test\trace_closure.php:7\{%s} (trace_closure.php, 1, cli)
    _dd.p.tid => %s
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure.php:7
    result => 2
  test\foo.{closure} (trace_closure.php, 2, cli)
    _dd.p.tid => %s
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure.php:12
    result => 3
  test\bar.foo.{closure} (trace_closure.php, 3, cli)
    _dd.p.tid => %s
    closure.declaration => %stests%cext%csandbox%cinstall_hook%ctrace_closure.php:19
    result => 4
}
