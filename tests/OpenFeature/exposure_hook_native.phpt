--TEST--
FFE OpenFeature records each eligible exposure after the evaluation hooks succeed
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: OpenFeature requires PHP 8+');
if (!is_file(__DIR__ . '/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
?>
--ENV--
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:9
--FILE--
<?php
require __DIR__ . '/../Common/FeatureFlags/flag_evaluation_openfeature.inc';
\DDTrace\Testing\ffe_load_config(json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => true,
            'splits' => array(array('variationKey' => 'on', 'serialId' => 4, 'shards' => array(), 'features' => array(
                array('key' => 'holdout.key', 'value' => 'h1', 'destinations' => array('HOOK')),
            ))),
        )),
    )),
)));
$recorded = array();
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_exposure', null, function ($hook) use (&$recorded) {
    $recorded[] = $hook->args[1];
});
$api = \OpenFeature\OpenFeatureAPI::getInstance();
$api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
$api->addHooks(new \DDTrace\OpenFeature\ExposureHook(function ($evaluation) {
    echo json_encode(array(
        'subject' => $evaluation->getTargetingKey(),
        'exposure' => $evaluation->isExposure(),
        'serial' => $evaluation->getSplitSerialId(),
        'features' => $evaluation->getFeatures(),
    )), "\n";
}));
$client = $api->getClient();
$context = fn ($key) => new \OpenFeature\implementation\flags\EvaluationContext($key);
$client->getStringValue('flag', 'off', $context('user'));
$client->getStringValue('flag', 'off', $context('user'));
$hook = new FfeOutcomeHook();
$hook->onAfter = function () { throw new RuntimeException('after failed'); };
$client->addHooks($hook);
echo $client->getStringValue('flag', 'off', $context('rejected')), "\n";
echo json_encode($recorded), "\n";
var_dump(\DDTrace\Testing\flush_ffe_exposures());
?>
--EXPECT--
{"subject":"user","exposure":true,"serial":4,"features":{"holdout.key":"h1"}}
{"subject":"user","exposure":true,"serial":4,"features":{"holdout.key":"h1"}}
{"subject":"rejected","exposure":false,"serial":null,"features":[]}
off
["user","user"]
bool(false)
