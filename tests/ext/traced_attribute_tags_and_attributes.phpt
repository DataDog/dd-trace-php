--TEST--
#[DDTrace\Trace] $tags are written like $meta (special keys set properties), $attributes are merged as is and win
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: attributes require PHP 8.0'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
error_reporting=E_ALL
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';
#[DDTrace\Trace(name: "tags_only", tags: ["component" => "tag-component", "span.kind" => "client", "count" => 3])]
function tagsOnly() {}

#[DDTrace\Trace(name: "attributes_only", attributes: ["component" => "attr-component", "count" => 3, "ok" => true])]
function attributesOnly() {}

#[DDTrace\Trace(name: "both", tags: ["shared" => "from tags", "tag" => "t"], attributes: ["shared" => "from attributes", "attr" => 1])]
function both() {}

echo "calls\n";
tagsOnly();
tagsOnly();
attributesOnly();
both();

foreach (array_reverse(dd_clean_spans()) as $span) {
    $attributes = array_intersect_key($span['attributes'], array_flip(['component', 'count', 'ok', 'shared', 'tag', 'attr']));
    ksort($attributes);
    echo $span['name'], ": component=", json_encode($span['component'] ?? null), " span_kind=", json_encode($span['span_kind'] ?? null),
        " attributes=", json_encode($attributes), "\n";
}
?>
--EXPECTF--
Deprecated: DDTrace\Trace: $tags is deprecated, use $attributes instead in %s on line %d

Deprecated: DDTrace\Trace: $tags is deprecated, use $attributes instead in %s on line %d
calls
tags_only: component="tag-component" span_kind=3 attributes={"count":"3"}
tags_only: component="tag-component" span_kind=3 attributes={"count":"3"}
attributes_only: component=null span_kind=null attributes={"component":"attr-component","count":3,"ok":true}
both: component=null span_kind=null attributes={"attr":1,"shared":"from attributes","tag":"t"}
