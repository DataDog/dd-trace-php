--TEST--
Manual override of span service produces _dd.svc_src = 'm'
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$root = \DDTrace\start_span();
$root->name = 'root';
$root->service = 'app';

$child = \DDTrace\start_span();
$child->name = 'child';
$child->service = 'overridden';

\DDTrace\close_span();
\DDTrace\close_span();

$byName = [];
foreach (dd_trace_serialize_closed_spans() as $s) { $byName[$s['name']] = $s; }
echo "root svc_src: " . ($byName['root']['meta']['_dd.svc_src'] ?? '(unset)') . "\n";
echo "child svc_src: " . ($byName['child']['meta']['_dd.svc_src'] ?? '(unset)') . "\n";
?>
--EXPECT--
root svc_src: (unset)
child svc_src: m
