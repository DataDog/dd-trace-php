--TEST--
Tracing Closures via install_hook()
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

namespace test;

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

$closures = [$topLevelClosure, foo(), bar::foo()];
$hooks = [];

foreach ($closures as $i => $closure) {
    $hooks[] = \DDTrace\install_hook($closure, function(\DDTrace\HookData $hook) use ($i) {
        $hook->span()->resource = $i;
        $hook->data = $hook->args[0];
    }, function(\DDTrace\HookData $hook) {
        $hook->span()->meta['result'] = $hook->returned;
    });

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
spans(\DDTrace\SpanData) (6) {
  test\trace_closure.php:5\{closure} (trace_closure.php, 0, cli)
    closure.declaration => %s:5
    result => 1
  test\foo.{closure} (trace_closure.php, 1, cli)
    closure.declaration => %s:10
    result => 2
  test\bar.foo.{closure} (trace_closure.php, 2, cli)
    closure.declaration => %s:17
    result => 3
  test\trace_closure.php:5\{closure} (trace_closure.php, 0, cli)
    closure.declaration => %s:5
    result => 2
  test\foo.{closure} (trace_closure.php, 1, cli)
    closure.declaration => %s:10
    result => 3
  test\bar.foo.{closure} (trace_closure.php, 2, cli)
    closure.declaration => %s:17
    result => 4
}
