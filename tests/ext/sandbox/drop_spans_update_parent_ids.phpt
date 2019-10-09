--TEST--
Update child span's parent ID's when span is dropped
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

dd_trace_function('main', function (SpanData $span) {
    $span->name = 'Main';
});
dd_trace_function('dropMe', function (SpanData $span) {
    $span->name = 'DropMe';
    return false;
});
dd_trace_function('array_sum', function (SpanData $span) {
    $span->name = 'ArraySum';
});

function dropMe() {
    return array_sum([1, 2]) + array_sum([3, 4]);
}
function main() {
    dropMe();
}
main();

$mainSpanId = 0;
$droppedSpanId = 0; // Should not change
$targetChildren = [];
array_map(function($span) use (&$mainSpanId, &$targetChildren, &$droppedSpanId) {
    switch ($span['name']) {
        case 'Main':
            $mainSpanId = $span['span_id'];
            break;
        case 'ArraySum':
            $targetChildren[] = $span;
            break;
        case 'DropMe':
            $droppedSpanId = $span['span_id'];
            break;
    }
}, dd_trace_serialize_closed_spans());

var_dump($mainSpanId === $targetChildren[0]['parent_id']);
var_dump($mainSpanId === $targetChildren[1]['parent_id']);
var_dump($droppedSpanId);
?>
--EXPECT--
bool(true)
bool(true)
int(0)
