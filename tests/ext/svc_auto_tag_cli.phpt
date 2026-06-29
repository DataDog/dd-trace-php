--TEST--
Process tags include svc.auto:<default> when DD_SERVICE is unset (CLI)
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$span = \DDTrace\start_span();
$span->name = 'op';
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
$processTags = $spans[0]['meta']['_dd.tags.process'];

echo "has svc.user: " . (strpos($processTags, 'svc.user') !== false ? 'YES' : 'NO') . "\n";
echo "has svc.auto: " . (strpos($processTags, 'svc.auto:') !== false ? 'YES' : 'NO') . "\n";
echo "auto value matches script: " . (strpos($processTags, 'svc.auto:svc_auto_tag_cli.php') !== false ? 'YES' : 'NO') . "\n";
?>
--EXPECT--
has svc.user: NO
has svc.auto: YES
auto value matches script: YES
