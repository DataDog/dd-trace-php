--TEST--
FFE OpenFeature commits prerequisite exposures only after all hooks succeed
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80100) die('skip: fibers require PHP 8.1+');
if (!is_file(__DIR__ . '/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
?>
--INI--
datadog.trace.log_file={PWD}/dependent_exposures_final_outcome.debug.log
datadog.trace.log_level="debug,ddtrace=trace"
--ENV--
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--FILE--
<?php
require __DIR__ . '/../Common/FeatureFlags/flag_evaluation_openfeature.inc';
$config = json_decode(file_get_contents(__DIR__ . '/../ext/ffe/dependent_flags.json'), true);
foreach ($config['flags'] as &$flag) {
    $flag['variationType'] = 'STRING';
    foreach ($flag['variations'] as &$variation) $variation['value'] = $variation['key'];
    unset($variation);
}
unset($flag);
$config['flags']['parent']['allocations'][0]['splits'][0]['serialId'] = 7;
$config['flags']['root']['allocations'][0]['splits'][0]['serialId'] = 0;
DDTrace\Testing\ffe_load_config(json_encode($config));
function queuedExposureCount() {
    return substr_count(file_get_contents(__DIR__ . '/dependent_exposures_final_outcome.debug.log'), 'exposure queued');
}
$committed = [];
DDTrace\install_hook('DDTrace\\Internal\\record_ffe_exposure', function ($hook) use (&$committed) {
    $committed[] = $hook->args;
});
$provider = new DDTrace\OpenFeature\DataDogProvider();
$hook = new FfeOutcomeHook();
$client = ffeOutcomeClient($provider, $hook);
$context = new OpenFeature\implementation\flags\EvaluationContext('same-subject', new OpenFeature\implementation\flags\Attributes(['plan' => 'pro']));
$hook->onAfter = function () use (&$committed) {
    if ($committed !== []) throw new LogicException('exposures committed before after hooks');
    throw new RuntimeException('reject result');
};
$details = $client->getStringDetails('root', 'fallback', $context);
echo 'after_error='; var_dump($details->getValue() === 'fallback' && $committed === [] && queuedExposureCount() === 0
    && array_map(function ($r) { return [$r[0], $r[5]]; }, $ffeRecords) === [['parent', null], ['root', 'GENERAL']]);

$ffeRecords = [];
$hook->onAfter = function () use (&$committed) {
    if ($committed !== []) throw new LogicException('exposures committed before after hooks');
};
$details = $client->getStringDetails('root', 'fallback', $context);
echo 'retry='; var_dump($details->getValue() === 'on' && count($committed) === 2 && queuedExposureCount() === 2
    && $committed[0] === ['parent', 'same-subject', ['plan' => 'pro'], 'parent-allocation', 'on', 7, true]
    && $committed[1] === ['root', 'same-subject', ['plan' => 'pro'], 'root-allocation', 'on', 0, true]);

$committed = [];
$ffeRecords = [];
$hook->onAfter = null;
$hook->onBefore = function () { throw new RuntimeException('before failed'); };
$details = $client->getStringDetails('root', 'fallback', $context);
echo 'before_error='; var_dump($details->getValue() === 'fallback' && $committed === []
    && queuedExposureCount() === 2 && count($ffeRecords) === 1 && $ffeRecords[0][0] === 'root' && $ffeRecords[0][5] === 'GENERAL');
$hook->onBefore = null;

// A nested direct-provider call owns its immediate batch independently.
$hook->onAfter = function ($context) use ($provider) {
    if ($context->getFlagKey() === 'root') {
        $provider->resolveStringValue('parent', 'fallback');
        throw new RuntimeException('outer failed');
    }
};
$client->getStringDetails('root', 'fallback', $context);
echo 'nested='; var_dump(count($committed) === 1 && $committed[0][0] === 'parent' && $committed[0][1] === null);

// Suspend after native evaluation, then complete another invocation on the same provider.
$committed = [];
$hook->onAfter = function ($context) {
    if ($context->getFlagKey() === 'root') {
        Fiber::suspend();
        throw new RuntimeException('suspended result rejected');
    }
};
$first = new Fiber(function () use ($client, $context) { return $client->getStringValue('root', 'fallback', $context); });
$second = new Fiber(function () use ($client) {
    return $client->getStringValue('parent', 'fallback', new OpenFeature\implementation\flags\EvaluationContext('other-subject'));
});
$first->start();
echo 'suspended='; var_dump($committed === []);
$second->start();
$first->resume();
echo 'fibers='; var_dump($first->getReturn() === 'fallback' && $second->getReturn() === 'on'
    && count($committed) === 1 && $committed[0][0] === 'parent' && $committed[0][1] === 'other-subject');

// A provider failure keeps its prerequisite observation without an exposure.
$committed = [];
$ffeRecords = [];
$hook->onAfter = null;
$details = $client->getStringDetails('missing', 'fallback', $context);
echo 'graph_error='; var_dump($details->getValue() === 'fallback' && $committed === []
    && count($ffeRecords) === 2 && $ffeRecords[0][5] === 'FLAG_NOT_FOUND' && $ffeRecords[1][5] === 'GENERAL');
?>
--EXPECT--
after_error=bool(true)
retry=bool(true)
before_error=bool(true)
nested=bool(true)
suspended=bool(true)
fibers=bool(true)
graph_error=bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/dependent_exposures_final_outcome.debug.log'); ?>
