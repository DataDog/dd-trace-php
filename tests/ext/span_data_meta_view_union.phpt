--TEST--
Tag views support array union and compound property assignment without mutating plain union operands
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function check($label, $actual, $expected) {
    echo $label, ': ';
    if ($actual === $expected) {
        echo "ok\n";
    } else {
        var_dump($actual, $expected);
    }
}

$span = DDTrace\start_span();
$span->attributes = ['kept' => 'left', 7 => 'seven', 'metric' => 1];
$span->name = 'Mixed.Case';
$meta = $span->meta;
$metrics = $span->metrics;
$snapshot = (array) $meta;
$attributes = $span->attributes;
$rhs = ['kept' => 'right', 7 => 'replaced', 11 => 'eleven', 'added' => 42];

$ref = &$meta['kept'];
$union = $meta + $rhs;
check('view + array', $union, $snapshot + $rhs);
check('array + view', $rhs + $meta, $rhs + $snapshot);
check('view + view', $meta + $metrics, $snapshot + ['metric' => 1]);
check('view + itself', $meta + $meta, $snapshot);
check('plain unions leave attributes unchanged', $span->attributes, $attributes);
$ref = 'changed';
check('union detaches live references', $union['kept'], 'left');
$ref = 'left';
unset($ref);
$union['kept'] = 'union-only';
check('union writes leave the view unchanged', $meta['kept'], 'left');

$span->meta += $rhs;
check('meta += keeps left and numeric keys', [$meta['kept'], $meta[7], $meta[11]], ['left', 'seven', 'eleven']);
check('meta += normalizes new values', $span->attributes['added'], '42');
check('meta += preserves unchanged aliases', $span->name, 'Mixed.Case');
check('meta += preserves the metrics bucket', $metrics['metric'], 1);
$span->metrics += ['metric' => 99, 'new_metric' => '2.5', 23 => 3];
check('metrics += uses the property setter', [$metrics['metric'], $metrics['new_metric'], $metrics[23]], [1.0, 2.5, 3.0]);
check('metrics += preserves the meta bucket', $meta['added'], '42');
check('retained views remain live', [$meta === $span->meta, $metrics === $span->metrics], [true, true]);

// Compound operations can alias the result with either kind of left operand.
$snapshot = (array) $meta;
$local = $meta;
$local += ['local' => 'only'];
check('local view += returns an array', $local, $snapshot + ['local' => 'only']);
check('local view += leaves the span unchanged', isset($meta['local']), false);
$local = ['kept' => 'array-left', 7 => 'array-seven'];
$expected = $local + $snapshot;
$local += $meta;
check('local array += view', $local, $expected);
$local = $meta;
$local += $local;
check('local view += itself', $local, $snapshot);

DDTrace\close_span();
?>
--EXPECT--
view + array: ok
array + view: ok
view + view: ok
view + itself: ok
plain unions leave attributes unchanged: ok
union detaches live references: ok
union writes leave the view unchanged: ok
meta += keeps left and numeric keys: ok
meta += normalizes new values: ok
meta += preserves unchanged aliases: ok
meta += preserves the metrics bucket: ok
metrics += uses the property setter: ok
metrics += preserves the meta bucket: ok
retained views remain live: ok
local view += returns an array: ok
local view += leaves the span unchanged: ok
local array += view: ok
local view += itself: ok
