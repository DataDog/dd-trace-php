--TEST--
Empty attribute keys and values survive the native read-back (empty Rust slices carry a dangling ptr)
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$s = \DDTrace\start_span();
$s->attributes = [
    '' => 'empty key', // dropped: a top-level attribute needs a key
    'empty' => '',
    'nested' => ['a' => ['b' => ['' => 1, 'c' => '']]],
    'list' => ['', ['' => '']],
];
\DDTrace\close_span();

$attrs = dd_trace_serialize_closed_spans()[0]['attributes'];
foreach (['', 'empty', 'nested', 'list'] as $key) {
    echo var_export($key, true), ' => ', json_encode(isset($attrs[$key]) ? $attrs[$key] : null), "\n";
}
?>
--EXPECT--
'' => null
'empty' => ""
'nested' => {"a":{"b":{"":1,"c":""}}}
'list' => ["",{"":""}]
