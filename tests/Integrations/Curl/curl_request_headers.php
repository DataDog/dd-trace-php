<?php

$ch = curl_init('http://httpbin_integration/headers');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
echo curl_exec($ch);
