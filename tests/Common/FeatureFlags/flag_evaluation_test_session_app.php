<?php
require __DIR__ . '/ffe_api_bootstrap.inc';
$config = array(
    'createdAt' => '2026-05-22T00:00:00Z',
    'environment' => array('name' => 'test'),
    'observeFullEvaluationData' => false,
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('blue' => array('key' => 'blue', 'value' => 'blue')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => true,
            'splits' => array(array('variationKey' => 'blue', 'serialId' => 0, 'shards' => array())),
        )),
    )),
);
if (!\DDTrace\Testing\ffe_load_config(json_encode($config))) throw new RuntimeException('configuration rejected');
$client = new \DDTrace\FeatureFlags\Client();
foreach (array('session-a', 'session-b') as $token) {
    ini_set('datadog.trace.agent_test_session_token', $token);
    if ($client->getStringValue('flag', 'fallback', array('targetingKey' => 'subject')) !== 'blue') {
        throw new RuntimeException('assignment changed');
    }
    // Hand off exposures before switching sessions. Flagevaluations may
    // still be queued and must retain their original destination.
    if (!\DDTrace\Testing\flush_ffe_exposures()) throw new RuntimeException('exposure flush rejected');
}
dd_trace_synchronous_flush(5000);
exit(0);
