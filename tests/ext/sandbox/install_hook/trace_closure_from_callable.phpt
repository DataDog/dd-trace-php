--TEST--
Tracing Fake Closures via install_hook()
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

function foo() {
}

$closure = (new ReflectionFunction('foo'))->getClosure();

\DDTrace\install_hook('foo', function(\DDTrace\HookData $hook) {
    $hook->span()->meta['global'] = 1;
}, null, DDTrace\HOOK_INSTANCE);

$hook = \DDTrace\install_hook($closure, function(\DDTrace\HookData $hook) {
    $hook->span()->meta['fake'] = 1;
}, null, DDTrace\HOOK_INSTANCE);

foo(); // Not traced by closure hook
$closure();

DDTrace\remove_hook($hook);

// Only foo hook
$closure();
foo();

include __DIR__ . '/../dd_dumper.inc';
\dd_dump_spans(true);

?>
--EXPECT--
spans(\DDTrace\SpanData) (4) {
  foo (trace_closure_from_callable.php, foo, cli)
    global => 1
  foo (trace_closure_from_callable.php, foo, cli)
    global => 1
    fake => 1
  foo (trace_closure_from_callable.php, foo, cli)
    global => 1
  foo (trace_closure_from_callable.php, foo, cli)
    global => 1
}
