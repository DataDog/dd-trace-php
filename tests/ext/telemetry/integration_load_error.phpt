--TEST--
Send logs from integration
--SKIPIF--
<?php
if (!extension_loaded('curl')) die('skip: curl extension required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TELEMETRY_LOG_COLLECTION_ENABLED=1
DD_TRACE_LOG_LEVEL=warn
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
--INI--
datadog.trace.agent_url="file://{PWD}/integration-load-error-telemetry.out"
--FILE--
<?php

class CurlIntegration implements \DDTrace\Integration
{
    function init(): int
    {
        if (!extension_loaded('curl')) {
            return Integration::NOT_AVAILABLE;
        }
        return self::LOADED;
    }
}


$ch = curl_init('https://raw.githubusercontent.com/DataDog/dd-trace-php/master/README.md');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/integration-load-error-telemetry.out')) {
        foreach (file(__DIR__ . '/integration-load-error-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    if ($json["request_type"] == "logs") {
                        $logs = $json['payload'];
                        ksort($logs);
                        var_dump(array_values($logs));

                        break 3;
                    }
                }
            }
        }
    }
}


?>
--EXPECTF--
[ddtrace] [warning] Error loading deferred integration DDTrace\Integrations\Curl\CurlIntegration: Class not loaded and not autoloadable
array(1) {
  [0]=>
  array(6) {
    ["message"]=>
    string(115) "Error loading deferred integration DDTrace\Integrations\Curl\CurlIntegration: Class not loaded and not autoloadable"
    ["level"]=>
    string(4) "WARN"
    ["count"]=>
    int(1)
    ["stack_trace"]=>
    NULL
    ["tags"]=>
    string(0) ""
    ["is_sensitive"]=>
    bool(false)
  }
}

--CLEAN--
<?php

@unlink(__DIR__ . '/integration-load-error-telemetry.out');

