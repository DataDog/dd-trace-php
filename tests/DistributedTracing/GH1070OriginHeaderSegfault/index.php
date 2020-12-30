<?php

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://httpbin_integration/get');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$output = curl_exec($ch);
curl_close($ch);
