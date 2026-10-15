--TEST--
#[DDTrace\Trace] kind and component set the span properties; same-named $attributes stay raw
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: attributes require PHP 8.0'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';
#[DDTrace\Trace(name: "kind_component", kind: DDTrace\SpanKind::CLIENT, component: "my-component")]
function kindComponent() {}

#[DDTrace\Trace(name: "with_attributes", kind: DDTrace\SpanKind::PRODUCER, component: "property", attributes: ["component" => "raw", "span.kind" => "server"])]
function withAttributes() {}

#[DDTrace\Trace(name: "unset")]
function notSet() {}

kindComponent();
withAttributes();
notSet();

foreach (array_reverse(dd_clean_spans()) as $span) {
    $attributes = array_intersect_key($span['attributes'], array_flip(['component', 'span.kind']));
    ksort($attributes);
    echo $span['name'], ": component=", json_encode($span['component'] ?? null), " span_kind=", json_encode($span['span_kind'] ?? null),
        " attributes=", json_encode($attributes), "\n";
}
?>
--EXPECT--
kind_component: component="my-component" span_kind=3 attributes=[]
with_attributes: component="property" span_kind=4 attributes={"component":"raw","span.kind":"server"}
unset: component=null span_kind=null attributes=[]
