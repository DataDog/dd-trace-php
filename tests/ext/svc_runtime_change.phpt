--TEST--
Changing datadog.service at runtime recomputes svc.user/svc.auto process tags per-span
--ENV--
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
function assert_tags($label) {
    $spans = dd_trace_serialize_closed_spans();
    $tags = $spans[0]['meta']['_dd.tags.process'];
    $hasUser = strpos($tags, 'svc.user:true') !== false;
    $hasAuto = strpos($tags, 'svc.auto:') !== false;
    echo "$label svc.user=" . ($hasUser ? 'YES' : 'NO') . " svc.auto=" . ($hasAuto ? 'YES' : 'NO') . "\n";
}

$span1 = \DDTrace\start_span();
$span1->name = 'before';
\DDTrace\close_span();
assert_tags('BEFORE  ');

ini_set('datadog.service', 'changed-svc');
$span2 = \DDTrace\start_span();
$span2->name = 'after_set';
\DDTrace\close_span();
assert_tags('AFTER   ');

ini_restore('datadog.service');
$span3 = \DDTrace\start_span();
$span3->name = 'after_restore';
\DDTrace\close_span();
assert_tags('REVERTED');
?>
--EXPECT--
BEFORE   svc.user=NO svc.auto=YES
AFTER    svc.user=YES svc.auto=NO
REVERTED svc.user=NO svc.auto=YES
