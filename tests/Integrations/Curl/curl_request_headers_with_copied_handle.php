<?php

$ch1 = curl_init('http://httpbin_integration/headers');
\curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch1, CURLOPT_HTTPHEADER, [
    'honored: preserved_value',
]);
$ch2 = \curl_copy_handle($ch1);
\curl_close($ch1);
echo curl_exec($ch2);
