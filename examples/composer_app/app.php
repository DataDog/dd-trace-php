<?php

namespace My\App;

use Elasticsearch\Client;

require __DIR__ . '/vendor/autoload.php';

// Using an integration that instruments a PHP ext, so no entry in composer
$client = new \Memcached();
$client->addServer('memcached_integration', '11211');
$client->add('key', 'value');
$value = $client->get('key');
error_log("Found @key: " . print_r($value, 1));

// Using an integration that instruments a PHP library from composer
$elastic = new Client([
    'hosts' => [
        'elasticsearch2_integration',
    ],
]);
$elastic->index([
    'id' => 1,
    'index' => 'my_index',
    'type' => 'my_type',
    'body' => ['my' => 'body'],
]);
$elastic->indices()->flush();
$exists = $elastic->exists([
    'id' => 1,
    'index' => 'my_index',
    'type' => 'my_type',
]);
error_log("Index exists? " . print_r($exists, 1));
