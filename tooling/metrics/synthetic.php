<?php

const CURL_COUNT = 30;
const QUERY_COUNT = 100;
const CACHE_COUNT = 100;

for ($curlIndex = 0; $curlIndex < CURL_COUNT; $curlIndex++) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'httpbin-integration/get?client=curl');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }
}

for ($queryIndex = 0; $queryIndex < QUERY_COUNT; $queryIndex++) {
    $pdo = new \PDO('mysql:host=mysql-integration;dbname=test', 'test', 'test');
    $stm = $pdo->query("SELECT VERSION()");
    $version = $stm->fetch();
    $pdo = null;
}

for ($cacheIndex = 0; $cacheIndex < CACHE_COUNT; $cacheIndex++) {
    $redis = new \Redis();
    $redis->connect('redis-integration', 6379);
    $redis->set('k1', 'v1');
    $redis->get('k1');
}
