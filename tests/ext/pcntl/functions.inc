<?php

function call_httpbin()
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "httpbin_integration/get");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_exec($ch);
    curl_close($ch);
}
