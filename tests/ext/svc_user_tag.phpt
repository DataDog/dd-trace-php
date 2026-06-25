--TEST--
Process tags include svc.user:true when DD_SERVICE is set
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_SERVICE=my-app
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$span = \DDTrace\start_span();
$span->name = 'op';
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
$processTags = $spans[0]['meta']['_dd.tags.process'];

echo "has svc.user:true: " . (strpos($processTags, 'svc.user:true') !== false ? 'YES' : 'NO') . "\n";
echo "has svc.auto:   : " . (strpos($processTags, 'svc.auto:') !== false ? 'YES' : 'NO') . "\n";
?>
--EXPECT--
has svc.user:true: YES
has svc.auto:   : NO
