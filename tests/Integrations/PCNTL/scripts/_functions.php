<?php

function call_httpbin($path)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "httpbin-integration/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);
}
