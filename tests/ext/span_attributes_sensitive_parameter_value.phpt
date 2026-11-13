--TEST--
A SensitiveParameterValue tag or attribute (no JSON properties) serializes as an empty map instead of crashing
--SKIPIF--
<?php if (PHP_VERSION_ID < 80200) die('skip: SensitiveParameterValue was added in PHP 8.2'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$span = \DDTrace\start_span();
$span->name = 'sensitive';
$secret = new SensitiveParameterValue('secret');
$span->meta['via_meta'] = $secret;
$span->attributes['via_attributes'] = $secret;
$span->attributes['nested'] = [$secret];
var_dump($span->meta['via_meta']);
\DDTrace\close_span();

$attrs = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0]['attributes'];
foreach (['via_meta', 'via_attributes', 'nested'] as $key) {
    echo $key, ' => ', json_encode(array_key_exists($key, $attrs) ? $attrs[$key] : 'absent'), "\n";
}
?>
--EXPECT--
array(0) {
}
via_meta => []
via_attributes => []
nested => [[]]
