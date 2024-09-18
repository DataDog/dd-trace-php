<?php

function boolstr($bool) {
    return ($bool ? 'YES' : 'NO');
}

echo "datadog.trace.enabled: ".boolstr(dd_trace_env_config('DD_TRACE_ENABLED'))."\n";

if (!extension_loaded('Zend OPcache')) {
    echo "OPcache is not loaded\n";
    return;
}

$status = opcache_get_status();
if ($status === false) {
    echo "OPcache is disabled\n";
    return;
}

echo "opcache_enabled: ".boolstr($status['opcache_enabled'])."\n";
echo "jit.enabled: ".boolstr($status['jit']['enabled'])."\n";
echo "jit.on: ".boolstr($status['jit']['on'])."\n";
echo "jit.buffer_size: ".$status['jit']['buffer_size']."\n";
