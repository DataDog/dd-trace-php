--TEST--
Default service name (DD_SERVICE or auto-resolved) leaves _dd.svc_src cleared
--ENV--
DD_SERVICE=my-app
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$root = \DDTrace\start_span();
$root->name = 'root';

$child = \DDTrace\start_span();
$child->name = 'child';

\DDTrace\close_span();
\DDTrace\close_span();

$byName = [];
foreach (dd_trace_serialize_closed_spans() as $s) { $byName[$s['name']] = $s; }
echo "root service: " . $byName['root']['service'] . "\n";
echo "child service: " . $byName['child']['service'] . "\n";
echo "root svc_src: " . ($byName['root']['meta']['_dd.svc_src'] ?? '(unset)') . "\n";
echo "child svc_src: " . ($byName['child']['meta']['_dd.svc_src'] ?? '(unset)') . "\n";
?>
--EXPECT--
root service: my-app
child service: my-app
root svc_src: (unset)
child svc_src: (unset)
