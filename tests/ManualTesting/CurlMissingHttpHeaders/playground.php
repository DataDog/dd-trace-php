<?php

use DDTrace\Bootstrap;
use DDTrace\Integrations\IntegrationsLoader;

Bootstrap::tracerOnce();
IntegrationsLoader::load();


$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://httpbin/headers',
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Host: payments.api.com'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FAILONERROR => false,
    CURLOPT_HEADER => false,
]);
//curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Host: payments.api.com']);
$found = json_decode(curl_exec($ch), 1);

error_log("Sent headers: " . print_r($found, 1));
