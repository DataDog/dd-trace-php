<?php
require __DIR__ . '/ffe_api_bootstrap.inc';
$config = json_decode(file_get_contents(__DIR__ . '/agentless_evp_config.json'), true);
if (!\DDTrace\Testing\ffe_load_config(json_encode($config))) throw new RuntimeException('configuration rejected');
$client = new \DDTrace\FeatureFlags\Client();
$details = $client->getStringDetails('transport-flag', 'fallback', array('targetingKey' => 'transport-user'));
if ($details->getValue() !== 'blue') throw new RuntimeException('evaluation failed');
dd_trace_synchronous_flush();
echo "delivered\n";
