<?php
function result($check)
{
    if ($check) {
        return "  \t[OK]" . PHP_EOL;
    }
    return "  \t[FAIL]" . PHP_EOL;
}

function check_agent_connectivity()
{
    $host = "localhost";
    if (function_exists('dd_trace_env_config')) {
        $host = dd_trace_env_config('DD_AGENT_HOST');
    } else {
        $_host = getenv('DD_AGENT_HOST');
        if ($_host) {
            $host = $_host;
        }
    }

    echo "- configured agent host\t\t\t\t" . $host . PHP_EOL;
    echo "- agent can receive traces\t\t";

    $verbose = fopen('php://temp', 'w+b');
    $ch = curl_init("http://" . $host . ":8126/v0.3/traces");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, "[]");
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $success = $httpcode >= 200 && $httpcode < 300;
    echo result($success);
    curl_close($ch);

    if (!$success) {
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        echo "Curl verbose output: " . PHP_EOL . PHP_EOL;
        echo $verboseLog . PHP_EOL;
    }
}

header('Content-Type: text/plain');
echo 'DataDog trace extension verification' . PHP_EOL . PHP_EOL;
echo 'Checks:' . PHP_EOL;
echo "- ddtrace extension installed\t\t" . result(extension_loaded('ddtrace') || extension_loaded('dd_trace'));
echo "- ddtrace extension version \t\t\t" . (phpversion('ddtrace') ?: '[N/A]') . PHP_EOL;
echo "- dd_trace function available\t\t" . result(function_exists('dd_trace'));
$initHook = ini_get('ddtrace.request_init_hook');
echo "- request_init_hook set\t\t\t" . result(!empty($initHook));
echo "- request_init_hook reachable\t\t" . result(file_exists(ini_get('ddtrace.request_init_hook')));
echo "- DDTrace\Tracer class exists\t\t" . result(class_exists('\DDTrace\Tracer'));

echo "- dd_trace_env_config function available" . result(function_exists('dd_trace_env_config'));
check_agent_connectivity();
if (file_exists(ini_get('ddtrace.request_init_hook'))) {
    // TODO how to check if this was already loaded ?

    echo '- requiring dd_init' . PHP_EOL;
    require dirname(ini_get('ddtrace.request_init_hook')) . '/dd_init.php';
}

echo PHP_EOL;
