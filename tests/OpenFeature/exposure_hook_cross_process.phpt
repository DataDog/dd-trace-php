--TEST--
Customer exposure hooks receive every eligible assignment across workers with Datadog delivery disabled
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000 || !is_file(__DIR__ . '/vendor/autoload.php')) die('skip: OpenFeature dependencies required');
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
?>
--ENV--
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
datadog.trace.log_file={PWD}/exposure_hook_cross_process.log
datadog.trace.log_level="debug,ddtrace=trace"
--FILE--
<?php
require __DIR__ . '/../Common/FeatureFlags/flag_evaluation_openfeature.inc';
$api = \OpenFeature\OpenFeatureAPI::getInstance();
$api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
$parent = getmypid();
$api->addHooks(new \DDTrace\OpenFeature\ExposureHook(function ($event) use ($parent) {
    echo json_encode([getmypid() === $parent ? 'parent' : 'child', $event->isExposure(), $event->getFlagKey(),
        $event->getTargetingKey(), $event->getAllocationKey(), $event->getVariant(), $event->getSplitSerialId(), $event->getFeatures()]), "\n";
}));
function evaluateAllocation($allocation) {
    $config = json_decode(ffeOutcomeConfig(), true);
    $rule = &$config['flags']['flag']['allocations'][0];
    $rule['key'] = $allocation;
    $rule['doLog'] = true;
    $rule['splits'][0]['features'] = [['key' => 'holdout.key', 'value' => 'h1', 'destinations' => ['HOOK']]];
    \DDTrace\Testing\ffe_load_config(json_encode($config));
    \OpenFeature\OpenFeatureAPI::getInstance()->getClient()->getStringValue('flag', 'off', new \OpenFeature\implementation\flags\EvaluationContext('user'));
    if (\DDTrace\Testing\flush_ffe_exposures()) throw new RuntimeException('unexpected Datadog exposure handoff');
}
evaluateAllocation('A');
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('fork failed');
if ($pid === 0) {
    evaluateAllocation('B');
    exit(0);
}
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new RuntimeException('child failed');
evaluateAllocation('A');
evaluateAllocation('A');
require __DIR__ . '/../ext/ffe/logging.inc';
$log = read_log_snapshot(__DIR__ . '/exposure_hook_cross_process.log');
echo 'handoff_attempts=', substr_count($log, 'attempting sidecar handoff'), "\n";
?>
--EXPECT--
["parent",true,"flag","user","A","blue",1,{"holdout.key":"h1"}]
["child",true,"flag","user","B","blue",1,{"holdout.key":"h1"}]
["parent",true,"flag","user","A","blue",1,{"holdout.key":"h1"}]
["parent",true,"flag","user","A","blue",1,{"holdout.key":"h1"}]
handoff_attempts=0
--CLEAN--
<?php @unlink(__DIR__ . '/exposure_hook_cross_process.log'); ?>
