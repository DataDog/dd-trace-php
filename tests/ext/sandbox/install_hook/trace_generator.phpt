--TEST--
Tracing Closures via install_hook()
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

namespace test;

$topLevelClosure = function() {
    yield 1;
    \DDTrace\start_span();
    yield 2;
    \DDTrace\close_span();
    return 3;
};

$hooks[] = \DDTrace\install_hook($topLevelClosure, function(\DDTrace\HookData $hook) {
    $hook->span();
}, function(\DDTrace\HookData $hook) {
    $hook->span()->meta['result'] = $hook->returned;
});

foreach ($topLevelClosure() as $val) {
}

include __DIR__ . '/../dd_dumper.inc';
\dd_dump_spans(true);

?>
--EXPECTF--
spans(\DDTrace\SpanData) (1) {
  test\trace_generator.php:5\{closure} (trace_generator.php, test\trace_generator.php:5\{closure}, cli)
    closure.declaration => /home/circleci/app/tmp/build_extension/tests/ext/sandbox/install_hook/trace_generator.php:5
    result => 3
     (trace_generator.php, cli)
}