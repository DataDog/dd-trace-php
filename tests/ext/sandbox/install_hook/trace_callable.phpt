--TEST--
Tracing generic callables via install_hook()
--INI--
datadog.trace.generate_root_span=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

namespace test;

function foo() {
    return 1;
}

class bar {
    static function foo() {
        return 2;
    }
}

function closure() {
    return function() {
        return 3;
    };
}

$ids = function() {
    return [
        (new \ReflectionFunction('test\foo'))->getClosure(),
        (new \ReflectionClass(bar::class))->getMethod("foo")->getClosure(),
        closure(),
    ];
};
$hooks = [];
foreach ($ids() as $i => $id) {
    $hooks[] = \DDTrace\install_hook($id, function(\DDTrace\HookData $hook) use ($i) {
        $hook->span()->resource = $i;
        $hook->data = $hook->args[0];
    }, function(\DDTrace\HookData $hook) {
        $hook->span()->meta['result'] = $hook->returned;
    });
}

foreach ($ids() as $id) {
    $id();
}

foreach ($hooks as $hook) {
    \DDTrace\remove_hook($hook);
}

// Not traced
foreach ($ids() as $id) {
    $id();
}

include __DIR__ . '/../dd_dumper.inc';
\dd_dump_spans(true);

?>
--EXPECTF--
spans(\DDTrace\SpanData) (3) {
  test\foo (trace_callable.php, 0, cli)
    result => 1
    _dd.p.tid => %s
  test\bar.foo (trace_callable.php, 1, cli)
    result => 2
    _dd.p.tid => %s
  test\closure.{closure} (trace_callable.php, 2, cli)
    closure.declaration => %s:%d
    result => 3
    _dd.p.tid => %s
}
