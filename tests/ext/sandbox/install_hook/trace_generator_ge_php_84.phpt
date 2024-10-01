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
  {closure:%s.php:%d\{closure} (trace_generator_ge_php_84.php, {closure:%s.php:%d\{closure}, cli)
    closure.declaration => %s:%d
    result => 3
    _dd.p.tid => %s
     (trace_generator_ge_php_84.php, cli)
}
