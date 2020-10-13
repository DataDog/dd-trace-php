<?php

$ch = curl_init('https://raw.githubusercontent.com/DataDog/dd-trace-php/master/README.md');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

header('Content-Type:text/plain');
echo curl_exec($ch);
