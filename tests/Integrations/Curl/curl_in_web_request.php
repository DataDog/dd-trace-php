<?php

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://httpbin_integration/status/200');
curl_exec($ch);
curl_close($ch);

echo "Done\n";
