<?php

require __DIR__ . '/ffe_api_bootstrap.inc';
echo "fixture_wire_ready\n";
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', null, function ($hook) {
    echo 'submission=', json_encode($hook->returned), "\n";
});

$config = array(
    'createdAt' => '2026-05-22T00:00:00Z',
    'environment' => array('name' => 'test'),
    'observeFullEvaluationData' => true,
    'flags' => array(),
);
foreach (array('consented', 'protected') as $key) {
    $config['flags'][$key] = array(
        'key' => $key, 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('blue' => array('key' => 'blue', 'value' => 'blue')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => true,
            'splits' => array(array('variationKey' => 'blue', 'serialId' => 0, 'shards' => array())),
        )),
    );
}
\DDTrace\Testing\ffe_load_config(json_encode($config));
$client = new \DDTrace\FeatureFlags\Client();
$context = array('targetingKey' => 'subject', 'attributes' => array('plan' => 'pro'));
if (getenv('PHP_FFE_WIRE_OPENFEATURE') === '1') {
    require $root . '/tests/OpenFeature/vendor/autoload.php';
    $api = \OpenFeature\OpenFeatureAPI::getInstance();
    $api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
    $client = $api->getClient('php-ffe-wire');
    $context = new \OpenFeature\implementation\flags\EvaluationContext('subject',
        new \OpenFeature\implementation\flags\Attributes(array('plan' => 'pro')));
}
for ($i = 0; $i < 2; ++$i) {
    if ($client->getStringValue('consented', 'fallback', $context) !== 'blue') {
        throw new RuntimeException('assignment failed');
    }
}
$config['observeFullEvaluationData'] = false;
\DDTrace\Testing\ffe_load_config(json_encode($config));
if ($client->getStringValue('protected', 'fallback', $context) !== 'blue') {
    throw new RuntimeException('assignment failed');
}
echo 'exposure_flush=', json_encode(\DDTrace\Testing\flush_ffe_exposures()), "\n";
echo "evaluated\n";
// Keep the live producer present until both writers reach the local capture.
// Fixed sleeps race instrumented sender startup; teardown has separate tests.
stream_set_timeout(STDIN, getenv('USE_ZEND_ALLOC') === '0' ? 30 : 10);
if (fgets(STDIN) !== "captures_received\n") {
    throw new RuntimeException("fixture did not acknowledge both EVP writers");
}
