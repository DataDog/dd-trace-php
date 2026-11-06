--TEST--
FFE native client writes split features and the exposure decision into flag metadata
--ENV--
DD_METRICS_OTEL_ENABLED=false
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/../../../Common/FeatureFlags/ffe_api_bootstrap.inc';
\DDTrace\Testing\ffe_load_config(json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => true,
            'splits' => array(array('variationKey' => 'on', 'serialId' => 9, 'shards' => array(), 'features' => array(
                array('key' => 'holdout.key', 'value' => 'h1', 'destinations' => array('HOOK')),
                array('key' => 'holdout.assignment_group', 'value' => 'status_quo', 'destinations' => array('HOOK', 'EXPOSURE')),
                array('key' => 'weight', 'value' => 1, 'destinations' => array('EVALUATION')),
            ))),
        )),
    )),
)));
$client = new \DDTrace\FeatureFlags\Client(new \DDTrace\Log\NullLogger('debug'));
foreach (array('first', 'repeat') as $label) {
    $details = $client->getStringDetails('flag', 'off', array('targetingKey' => 'user'));
    echo $label, '=', json_encode($details->getFlagMetadata(), JSON_PRESERVE_ZERO_FRACTION), "\n";
}
echo 'error=', json_encode($client->getBooleanDetails('flag', false, array('targetingKey' => 'user'))->getFlagMetadata()), "\n";
?>
--EXPECT--
first={"__dd_feature.holdout.key":"h1","__dd_feature.holdout.assignment_group":"status_quo","__dd_exposure_feature.holdout.assignment_group":"status_quo","__dd_evaluation_feature.weight":1.0,"__dd_do_log":true,"__dd_split_serial_id":9,"__dd_exposure_cache_hit":false}
repeat={"__dd_feature.holdout.key":"h1","__dd_feature.holdout.assignment_group":"status_quo","__dd_exposure_feature.holdout.assignment_group":"status_quo","__dd_evaluation_feature.weight":1.0,"__dd_do_log":true,"__dd_split_serial_id":9,"__dd_exposure_cache_hit":true}
error=[]
