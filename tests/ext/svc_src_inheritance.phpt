--TEST--
Child spans inherit _dd.svc_src from parent at creation time
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$root = \DDTrace\start_span();
$root->name = 'root';
$root->service = 'redis-srv';
$root->meta['_dd.svc_src'] = 'redis';

$child = \DDTrace\start_span();
$child->name = 'child';

\DDTrace\close_span();
\DDTrace\close_span();

$byName = [];
foreach (dd_trace_serialize_closed_spans() as $s) { $byName[$s['name']] = $s; }
echo "root svc_src: " . ($byName['root']['meta']['_dd.svc_src'] ?? '(unset)') . "\n";
echo "child svc_src: " . ($byName['child']['meta']['_dd.svc_src'] ?? '(unset)') . "\n";
?>
--EXPECT--
root svc_src: redis
child svc_src: redis
