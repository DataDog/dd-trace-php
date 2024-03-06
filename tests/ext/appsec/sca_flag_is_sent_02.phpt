--TEST--
DD_APPSEC_SCA_ENABLED flag is sent to via telemetry with true
--DESCRIPTION--
This configuration is used by the backend to display/charge customers
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_APPSEC_SCA_ENABLED=true
--INI--
datadog.trace.agent_url="file://{PWD}/simple-telemetry.out"
--FILE--
<?php

DDTrace\start_span();
$root = DDTrace\root_span();

$root->service = 'simple-telemetry-app';
$root->meta['env'] = 'test-env';

DDTrace\close_span();

// At this stage, the service and env are stored to be used by telemetry
dd_trace_serialize_closed_spans();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/simple-telemetry.out')) {
        $batches = [];
        foreach (file(__DIR__ . '/simple-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                array_push($batches, ...($json["request_type"] == "message-batch" ? $json["payload"] : [$json]));
            }
        }
        $found = array_filter($batches, function ($json) {
            if ($json["request_type"] !== "app-started") {
                return false;
            }
            foreach($json["payload"]["configuration"] as $configuration) {
                if ($configuration["name"] == "appsec_sca_enabled") {
                    var_dump($configuration);
                    return true;
                }
            }
            return false;
        });
        if (count($found) == 1) {
            var_dump("Sent");
            break;
        }
    }
}

?>
--EXPECT--
array(3) {
  ["name"]=>
  string(18) "appsec_sca_enabled"
  ["value"]=>
  string(4) "true"
  ["origin"]=>
  string(6) "EnvVar"
}
string(4) "Sent"
--CLEAN--
<?php

@unlink(__DIR__ . '/simple-telemetry.out');
