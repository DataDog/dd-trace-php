<?php

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port . '/get';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$output = curl_exec($ch);
curl_close($ch);
