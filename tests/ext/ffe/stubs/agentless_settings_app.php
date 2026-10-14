<?php
require __DIR__ . '/ffe_api_bootstrap.inc';
echo "fixture_child_ready\n";
if (fgets(STDIN) !== "evaluate\n") throw new RuntimeException('fixture did not activate evaluation');
$client = new \DDTrace\FeatureFlags\Client();
for ($i = 0; $i < 3; $i++) $details = $client->getStringDetails('flag', 'fallback');
echo json_encode(array('value' => $details->getValue(), 'state' => $details->getProviderState(), 'error' => $details->getErrorMessage()));
