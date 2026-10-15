--TEST--
$meta reads return the span properties that $meta writes of the legacy keys set
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$aliases = [
    'service.name' => 'svc',
    'resource.name' => 'res',
    'operation.name' => 'Op.Name',
    'span.type' => 'web',
    'env' => 'e1',
    'version' => 'v1',
    'component' => 'c1',
    'span.kind' => 'client',
    'error.ignored' => true,
];

$span = \DDTrace\start_span();
$view = &$span->meta;
foreach ($aliases as $key => $value) {
    $span->meta[$key] = $value;
    $meta = $span->meta;
    echo "$key: isset=", var_export(isset($span->meta[$key]), true),
        " read=", var_export($span->meta[$key], true),
        " view=", var_export($view[$key], true),
        " listed=", var_export($meta[$key] ?? null, true), "\n";
}
echo "count matches: ", var_export(count($view) === count($span->meta), true), "\n";
var_dump($span->attributes);

echo "-- the property wins over a same-named attribute\n";
$span->attributes['component'] = 'raw-component';
$span->meta['component'] = 'c2';
var_dump($span->meta['component'], $view['component'], $span->component, $span->attributes['component']);
echo "count matches: ", var_export(count($view) === count($span->meta), true), "\n";

echo "-- unset clears both\n";
unset($span->meta['component']);
var_dump(isset($span->meta['component']), isset($view['component']), $span->component, isset($span->attributes['component']));

echo "-- an attribute shows while the property is unset\n";
unset($view['env']);
$span->attributes['env'] = 'raw-env';
var_dump($span->env, $span->meta['env'], $view['env']);

echo "-- a bulk write leaves unchanged aliases alone\n";
$span->name = 'Mixed.Case';
$span->meta += ['added' => 'yes'];
var_dump($span->name, $span->env, $span->attributes['env'], $span->meta['added']);
unset($view);
\DDTrace\close_span();
?>
--EXPECTF--
service.name: isset=true read='svc' view='svc' listed='svc'
resource.name: isset=true read='res' view='res' listed='res'
operation.name: isset=true read='op.name' view='op.name' listed='op.name'
span.type: isset=true read='web' view='web' listed='web'
env: isset=true read='e1' view='e1' listed='e1'
version: isset=true read='v1' view='v1' listed='v1'
component: isset=true read='c1' view='c1' listed='c1'
span.kind: isset=true read='client' view='client' listed='client'
error.ignored: isset=true read='true' view='true' listed='true'
count matches: true
array(2) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  int(%d)
}
-- the property wins over a same-named attribute
string(2) "c2"
string(2) "c2"
string(2) "c2"
string(13) "raw-component"
count matches: true
-- unset clears both
bool(false)
bool(false)
string(0) ""
bool(false)
-- an attribute shows while the property is unset
string(0) ""
string(7) "raw-env"
string(7) "raw-env"
-- a bulk write leaves unchanged aliases alone
string(10) "Mixed.Case"
string(0) ""
string(7) "raw-env"
string(3) "yes"
