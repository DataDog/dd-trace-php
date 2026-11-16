<?php

// Keep provider loading, registration, and evaluation behind separate barriers.
// The parent measures real HTTP traffic while this process remains alive.
echo "fixture_child_ready\n";
if (trim((string) fgets(STDIN)) !== 'register') throw new RuntimeException('registration command missing');
require __DIR__ . '/ffe_api_bootstrap.inc';
require $root . '/tests/OpenFeature/vendor/autoload.php';
$api = \OpenFeature\OpenFeatureAPI::getInstance();
$api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
echo "fixture_provider_registered\n";
if (trim((string) fgets(STDIN)) !== 'evaluate') throw new RuntimeException('evaluation command missing');
$client = $api->getClient('php-agentless-activation');
echo 'fixture_value=', $client->getStringValue('flag', 'fallback'), "\n";
if (trim((string) fgets(STDIN)) !== 'finish') throw new RuntimeException('finish command missing');
