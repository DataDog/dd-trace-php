--TEST--
References that by-ref uses of $meta leave in $attributes are read through, and never shared with another span or array
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=1
DD_TRACE_RESOURCE_RENAMING_ENABLED=1
--FILE--
<?php
function set_by_ref(&$var, $value) {
    $var = $value;
}

$root = \DDTrace\start_span();
$root->name = 'root';
$root->type = 'web';
set_by_ref($root->meta['http.route'], '/users/{id}'); // a route: no http.endpoint is guessed

\DDTrace\add_distributed_tag('foo', 'bar');
$ref = &$root->meta['_dd.p.foo'];
unset($ref);
$context = @\DDTrace\current_context(); // deprecated
$context['distributed_tracing_propagated_tags']['_dd.p.foo'] = 'written through current_context()';
echo "propagated tag: ", $root->meta['_dd.p.foo'], "\n";

$root->meta['_dd.svc_src'] = 'root-src';
$svc_src = &$root->meta['_dd.svc_src'];
$child = \DDTrace\start_span(); // inherits _dd.svc_src
$child->name = 'child';
$svc_src = 'changed after child start';
unset($svc_src);
set_by_ref($child->meta['peer.service'], 'peer-from-tag');
\DDTrace\close_span();

$db = \DDTrace\start_span();
$db->name = 'db';
$db->peerServiceSources = ['db.instance'];
set_by_ref($db->meta['db.instance'], 'peer-from-source');
\DDTrace\close_span();

\DDTrace\close_span();

$chunk = dd_trace_serialize_closed_spans()['chunks'][0];
$keys = array_flip(['_dd.svc_src', 'http.route', 'http.endpoint', 'peer.service', '_dd.peer.service.source']);
$lines = [];
foreach ($chunk['spans'] as $span) {
    $attrs = array_intersect_key($span['attributes'] ?? [], $keys);
    ksort($attrs);
    $lines[] = $span['name'] . ': ' . json_encode($attrs, JSON_UNESCAPED_SLASHES);
}
sort($lines);
echo implode("\n", $lines), "\n";
?>
--EXPECT--
propagated tag: bar
child: {"_dd.peer.service.source":"peer.service","_dd.svc_src":"root-src","peer.service":"peer-from-tag"}
db: {"_dd.peer.service.source":"db.instance","_dd.svc_src":"changed after child start","peer.service":"peer-from-source"}
root: {"_dd.svc_src":"changed after child start","http.route":"/users/{id}"}
