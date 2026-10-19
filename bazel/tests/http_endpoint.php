<?php
if ($_SERVER['REQUEST_URI'] === '/health') {
    echo 'ok';
    return;
}
header('Content-Type: text/plain');
echo extension_loaded('ddtrace') ? 'ddtrace:' . phpversion('ddtrace') : 'missing-ddtrace';
