<?php
function result($check)
{
    if ($check) {
        return "  \t[OK]" . PHP_EOL;
    } else {
        return "  \t[FAIL]" . PHP_EOL;
    }
}
header('Content-Type: text/plain');
echo 'DataDog trace extension verification' . PHP_EOL . PHP_EOL;
echo 'Checks:' . PHP_EOL;
echo "- ddtrace extension installed" . result(extension_loaded('ddtrace') || extension_loaded('dd_trace'));
echo "- ddtrace extension version \t" . phpversion('ddtrace') . PHP_EOL;
echo "- dd_trace function available" . result(function_exists('dd_trace'));
echo "- request_init_hook set\t" . result(!empty(ini_get('ddtrace.request_init_hook')));
echo "- request_init_hook reachable" . result(file_exists(ini_get('ddtrace.request_init_hook')));
echo "- configured agent host   \t" . dd_trace_env_config('DD_AGENT_HOST') . PHP_EOL;
echo "- agent can receive traces ";
$ch = curl_init("http://" . dd_trace_env_config('DD_AGENT_HOST') . ":8126/v0.3/traces");
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch, CURLOPT_POSTFIELDS, "[]");
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
$data = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo result($httpcode >= 200 && $httpcode < 300);


echo PHP_EOL;

?>
