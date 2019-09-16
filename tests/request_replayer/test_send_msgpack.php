<?php

include __DIR__ . '/vendor/autoload.php';

use MessagePack\MessagePack;

$msgpack = MessagePack::pack([
    "sample" => "value",
]);

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL,"localhost");
curl_setopt($ch, CURLOPT_PORT, 80);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $msgpack);

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/msgpack',
]);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$server_output = curl_exec($ch);

curl_close ($ch);

print_r($server_output);
