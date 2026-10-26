--TEST--
Writing null to a $meta key aliasing a span property resets the property, as unsetting the key does
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$keys = array_flip(['env', 'version', 'component', 'span.kind', 'error.ignored']);
$span = \DDTrace\start_span();
$span->name = 'aliased';
$span->meta['env'] = 'meta-env';
$span->meta['version'] = 'meta-version';
$span->meta['component'] = 'meta-component';
$span->meta['span.kind'] = 'not-a-kind'; // not a canonical kind: stored as an attribute
$span->meta['error.ignored'] = true;
$span->attributes['env'] = 'attribute-env'; // a same-named attribute goes away too

$span->meta['env'] = null;
$span->meta['component'] = null;
$span->meta['span.kind'] = null;
$span->meta['error.ignored'] = null;
$span->meta = ['version' => null] + $span->meta;

var_dump($span->env, $span->version, $span->component, $span->spanKind, $span->ignoreError);
var_dump(array_intersect_key($span->meta, $keys), array_intersect_key($span->attributes, $keys));
\DDTrace\close_span();

$serialized = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0];
foreach (['env', 'version', 'component', 'span_kind'] as $field) {
    echo $field, '=', json_encode($serialized[$field] ?? null), "\n";
}
echo 'attributes=', json_encode(array_intersect_key($serialized['attributes'], $keys)), "\n";
?>
--EXPECT--
string(0) ""
string(0) ""
string(0) ""
int(0)
bool(false)
array(0) {
}
array(0) {
}
env=null
version=null
component=null
span_kind=null
attributes=[]
