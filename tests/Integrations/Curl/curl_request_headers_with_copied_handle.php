<?php

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';

$ch1 = curl_init($url);
\curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch1, CURLOPT_HTTPHEADER, [
    'honored: preserved_value',
]);
$ch2 = \curl_copy_handle($ch1);
\curl_close($ch1);
echo curl_exec($ch2);
