--TEST--
FFE OpenFeature observations keep nested calls and interleaved fibers separate
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80100) die('skip: fibers require PHP 8.1+');
if (!is_file(__DIR__ . '/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
?>
--ENV--
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:9
--FILE--
<?php
require __DIR__ . '/../Common/FeatureFlags/flag_evaluation_openfeature.inc';
\DDTrace\Testing\ffe_load_config(ffeOutcomeConfig());
$provider = new \DDTrace\OpenFeature\DataDogProvider();
$hook = new FfeOutcomeHook();
$client = ffeOutcomeClient($provider, $hook);
$hook->onAfter = function ($context) use ($client, $provider) {
    if ($context->getFlagKey() !== 'flag') return;
    $provider->resolveStringValue('inner', 'fallback');
    $client->getStringValue('inner', 'fallback');
    throw new RuntimeException('outer failed');
};
echo 'nested_result=', $client->getStringValue('flag', 'fallback'), "\n";
foreach ($ffeRecords as $record) echo json_encode(array($record[0], $record[1], $record[5], $record[6])), "\n";

// Suspend inside provider resolution, while its recorder is active. A second
// fiber must not capture its observation in the suspended invocation's scope.
$ffeRecords = array();
$hook->onAfter = null;
$suspend = \DDTrace\install_hook('DDTrace\\ffe_evaluate', null, function ($data) {
    if ($data->args[0] === 'flag') Fiber::suspend();
});
$first = new Fiber(function () use ($client) { return $client->getStringValue('flag', 'fallback'); });
$second = new Fiber(function () use ($client) { return $client->getStringValue('inner', 'fallback'); });
$first->start();
$second->start();
echo 'during_resolution='; var_dump(count($ffeRecords));
$first->resume();
\DDTrace\remove_hook($suspend);
echo 'resolution_results=', $first->getReturn(), '/', $second->getReturn(), "\n";
foreach ($ffeRecords as $record) echo json_encode(array($record[0], $record[1], $record[5], $record[6])), "\n";

$ffeRecords = array();
$hook->onAfter = function ($context) {
    if ($context->getFlagKey() === 'flag') {
        Fiber::suspend();
        throw new RuntimeException('suspended evaluation failed');
    }
};
$first = new Fiber(function () use ($client) { return $client->getStringValue('flag', 'fallback'); });
$second = new Fiber(function () use ($client) { return $client->getStringValue('inner', 'fallback'); });
$first->start();
$second->start();
echo 'while_suspended='; var_dump(count($ffeRecords));
$first->resume();
echo 'fiber_results=', $first->getReturn(), '/', $second->getReturn(), "\n";
foreach ($ffeRecords as $record) echo json_encode(array($record[0], $record[1], $record[5], $record[6])), "\n";
?>
--EXPECT--
nested_result=fallback
["inner","blue",null,false]
["inner","blue",null,false]
["flag",null,"GENERAL",true]
during_resolution=int(1)
resolution_results=blue/blue
["inner","blue",null,false]
["flag","blue",null,false]
while_suspended=int(1)
fiber_results=fallback/blue
["inner","blue",null,false]
["flag",null,"GENERAL",true]
